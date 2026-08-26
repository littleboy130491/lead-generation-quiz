<?php

namespace App\Domain\Quiz\Validation;

use App\Ai\Prompt\AnalysisPromptVariables;
use App\Domain\Quiz\Pagination\QuizPageCompiler;
use App\Enums\QuizResultMode;
use Illuminate\Validation\ValidationException;

class QuizDefinitionValidator
{
    private const BLOCK_TYPES = ['question', 'content', 'page_break'];

    private const QUESTION_TYPES = ['single_choice', 'multiple_choice', 'yes_no', 'short_text', 'long_text'];

    private const OPERATORS = ['equals', 'not_equals', 'in', 'not_in', 'contains', 'empty', 'not_empty', 'greater_than', 'less_than'];

    /** Validate the complete persisted schema-version 1 contract. */
    public function validate(array $definition): void
    {
        $this->ensureAllowedKeys($definition, ['schema_version', 'opening', 'result', 'score_results', 'thank_you', 'blocks'], 'Definition');
        $this->ensure(($definition['schema_version'] ?? null) === 1, 'Unsupported schema version.');
        $this->ensure(array_key_exists('blocks', $definition) && is_array($definition['blocks']) && array_is_list($definition['blocks']) && count($definition['blocks']) > 0 && count($definition['blocks']) <= 100, 'A definition requires one to one hundred ordered blocks.');
        $this->validateOpening($definition['opening'] ?? null);
        $mode = $this->validateResult($definition['result'] ?? null);
        $this->validateScoreResults($definition['score_results'] ?? null, $mode);
        $this->validateThankYou($definition['thank_you'] ?? null, $mode);

        $blockIds = [];
        $questions = [];
        foreach ($definition['blocks'] as $position => $block) {
            $this->ensure(is_array($block), 'Every block must be an object.');
            $id = $this->requiredStableId($block['id'] ?? null, 'Every block requires a stable ID.');
            $this->ensure(! isset($blockIds[$id]), 'Block IDs must be unique.');
            $blockIds[$id] = true;
            $type = $block['type'] ?? null;
            $this->ensure(in_array($type, self::BLOCK_TYPES, true), 'Unsupported block type.');
            $this->ensureAllowedKeys($block, match ($type) {
                'question' => ['id', 'type', 'question_type', 'label', 'help', 'required', 'max_length', 'options', 'visibility', 'yes_score', 'no_score', 'image_url', 'icon', 'exclude_from_ai'],
                'content' => ['id', 'type', 'markdown', 'continue_label', 'visibility'],
                'page_break' => ['id', 'type'],
            }, 'Block');

            if ($type === 'question') {
                $question = $this->validateQuestion($block);
                $this->ensure(! isset($questions[$id]), 'Question IDs must be unique.');
                $questions[$id] = ['position' => $position, ...$question];
            }
            if ($type === 'content') {
                $markdown = $this->requiredString($block['markdown'] ?? null, 'Content blocks require Markdown.', 10000);
                if (array_key_exists('continue_label', $block) && $block['continue_label'] !== null) {
                    $this->requiredString($block['continue_label'], 'Content continue labels must be nonempty when present.', 200);
                }
                $this->ensure(! preg_match('/<\\?(?:php|=)?|\{\{\s*|javascript\s*:/i', $markdown), 'Content Markdown contains executable or unsafe markup.');
            }
        }

        try {
            app(QuizPageCompiler::class)->compile($definition);
        } catch (\InvalidArgumentException $exception) {
            $this->fail($exception->getMessage());
        }

        foreach ($definition['blocks'] as $position => $block) {
            if (in_array($block['type'], ['question', 'content'], true) && array_key_exists('visibility', $block) && $block['visibility'] !== null) {
                $this->validateCondition($block['visibility'], $questions, $position);
            }
        }

        $this->validateAiSystemPrompt($definition['result'] ?? null, $mode, array_keys($questions));
    }

    private function validateOpening(mixed $opening): void
    {
        if ($opening === null) {
            return;
        }

        $this->ensure(is_array($opening), 'Opening must be an object when present.');
        $this->ensureAllowedKeys($opening, ['html', 'start_button_label', 'hide_start_button'], 'Opening');
        $html = $this->requiredString($opening['html'] ?? null, 'Opening HTML is required when an opening is present.', 40000);
        $this->ensure(! preg_match('/<\\?(?:php|=)?|\{\{\s*|javascript\s*:/i', $html), 'Opening HTML contains executable or unsafe markup.');
        if (array_key_exists('hide_start_button', $opening)) {
            $this->ensure(is_bool($opening['hide_start_button']), 'Opening hide_start_button must be a boolean.');
        }
        if (array_key_exists('start_button_label', $opening) && $opening['start_button_label'] !== null) {
            $this->requiredString($opening['start_button_label'], 'Opening start button labels must be nonempty when present.', 200);
        }
    }

    private function validateResult(mixed $result): QuizResultMode
    {
        if ($result === null) {
            return QuizResultMode::Ai;
        }

        $this->ensure(is_array($result), 'Result must be an object when present.');
        $this->ensureAllowedKeys($result, ['mode', 'system_prompt'], 'Result');
        $mode = $result['mode'] ?? null;
        $this->ensure(is_string($mode) && QuizResultMode::tryFrom($mode) !== null, 'Result mode must be ai or score.');

        return QuizResultMode::from($mode);
    }

    /** @param  list<string>  $questionIds */
    private function validateAiSystemPrompt(mixed $result, QuizResultMode $mode, array $questionIds): void
    {
        if (! is_array($result) || ! array_key_exists('system_prompt', $result) || $result['system_prompt'] === null) {
            return;
        }

        $this->ensure($mode === QuizResultMode::Ai, 'Result system_prompt is only allowed when result mode is ai.');
        $prompt = $this->requiredString($result['system_prompt'], 'Result system_prompt must be nonempty when present.', 10000);
        $this->ensure(! str_contains(strtolower($prompt), '<?'), 'Result system_prompt must not contain PHP.');
        $invalid = app(AnalysisPromptVariables::class)->disallowedPlaceholders($prompt, allowPerQuestion: true, questionIds: $questionIds);
        $this->ensure($invalid === [], 'Result system_prompt contains unsupported placeholders: '.implode(', ', $invalid).'.');
    }

    private function validateScoreResults(mixed $results, QuizResultMode $mode): void
    {
        if ($mode === QuizResultMode::Ai) {
            $this->ensure($results === null, 'Score results are only allowed when result mode is score.');

            return;
        }

        $this->ensure(is_array($results) && array_is_list($results) && count($results) > 0 && count($results) <= 50, 'Score mode requires one to fifty ordered result bands.');
        $ids = [];
        $ranges = [];
        foreach ($results as $band) {
            $this->ensure(is_array($band), 'Every score result must be an object.');
            $this->ensureAllowedKeys($band, ['id', 'title', 'min_score', 'max_score', 'html'], 'Score result');
            $id = $this->requiredStableId($band['id'] ?? null, 'Every score result requires a stable ID.');
            $this->ensure(! isset($ids[$id]), 'Score result IDs must be unique.');
            $ids[$id] = true;
            $this->requiredString($band['title'] ?? null, 'Score results require a nonempty title.', 500);
            $this->ensure(is_int($band['min_score'] ?? null) && is_int($band['max_score'] ?? null), 'Score result bounds must be integers.');
            $this->ensure($this->isValidScore($band['min_score']) && $this->isValidScore($band['max_score']), 'Score values must be between -10000 and 10000.');
            $this->ensure($band['min_score'] <= $band['max_score'], 'Score result min_score must be less than or equal to max_score.');
            if (array_key_exists('html', $band) && $band['html'] !== null) {
                $html = $this->requiredString($band['html'], 'Score result HTML must be nonempty when present.', 40000);
                $this->ensure(! preg_match('/<\\?(?:php|=)?|\{\{\s*|javascript\s*:/i', $html), 'Score result HTML contains executable or unsafe markup.');
            }
            foreach ($ranges as [$min, $max]) {
                $this->ensure($band['max_score'] < $min || $band['min_score'] > $max, 'Score result ranges must not overlap.');
            }
            $ranges[] = [$band['min_score'], $band['max_score']];
        }
    }

    private function validateThankYou(mixed $thankYou, QuizResultMode $mode): void
    {
        if ($thankYou === null) {
            return;
        }

        $this->ensure(is_array($thankYou), 'Thank you must be an object when present.');
        $this->ensureAllowedKeys($thankYou, ['enabled', 'html'], 'Thank you');
        if (array_key_exists('enabled', $thankYou)) {
            $this->ensure(is_bool($thankYou['enabled']), 'Thank you enabled must be a boolean.');
            if ($mode === QuizResultMode::Ai) {
                $this->ensure($thankYou['enabled'] === true, 'Thank you cannot be disabled when result mode is ai.');
            }
        }
        if (array_key_exists('html', $thankYou) && $thankYou['html'] !== null) {
            $html = $this->requiredString($thankYou['html'], 'Thank you HTML must be nonempty when present.', 40000);
            $this->ensure(! preg_match('/<\\?(?:php|=)?|\{\{\s*|javascript\s*:/i', $html), 'Thank you HTML contains executable or unsafe markup.');
        }
    }

    /** @return array{type:string,values:list<string>} */
    private function validateQuestion(array $block): array
    {
        $type = $block['question_type'] ?? null;
        $this->ensure(in_array($type, self::QUESTION_TYPES, true), 'Unsupported question type.');
        $this->requiredString($block['label'] ?? null, 'Questions require a nonempty label.', 500);
        if (array_key_exists('required', $block)) {
            $this->ensure(is_bool($block['required']), 'Question required must be a boolean.');
        }
        if (array_key_exists('exclude_from_ai', $block)) {
            $this->ensure(is_bool($block['exclude_from_ai']), 'Question exclude_from_ai must be a boolean.');
        }
        if (array_key_exists('help', $block) && $block['help'] !== null) {
            $this->requiredString($block['help'], 'Question help must be nonempty when present.', 2000);
        }
        if (array_key_exists('image_url', $block) && $block['image_url'] !== null) {
            $url = $this->requiredString($block['image_url'], 'Question image_url must be nonempty when present.', 2048);
            $this->ensure(filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true), 'Question image_url must be an http or https URL.');
        }
        if (array_key_exists('icon', $block) && $block['icon'] !== null) {
            $icon = $this->requiredString($block['icon'], 'Question icon must be nonempty when present.', 32);
            $this->ensure(! preg_match('/[<>{}]|javascript\s*:/i', $icon), 'Question icon must be plain text without markup.');
        }
        if (array_key_exists('max_length', $block)) {
            $maximum = $type === 'short_text' ? 1000 : ($type === 'long_text' ? 10000 : null);
            $this->ensure($maximum !== null && is_int($block['max_length']) && $block['max_length'] >= 1 && $block['max_length'] <= $maximum, 'Max length is only supported for text questions and must be within its type bound.');
        }
        if (array_key_exists('yes_score', $block) || array_key_exists('no_score', $block)) {
            $this->ensure($type === 'yes_no', 'Yes/no scores are only supported on yes_no questions.');
            if (array_key_exists('yes_score', $block)) {
                $this->ensure(is_int($block['yes_score']) && $this->isValidScore($block['yes_score']), 'yes_score must be an integer between -10000 and 10000.');
            }
            if (array_key_exists('no_score', $block)) {
                $this->ensure(is_int($block['no_score']) && $this->isValidScore($block['no_score']), 'no_score must be an integer between -10000 and 10000.');
            }
        }
        $values = $type === 'yes_no' ? ['yes', 'no'] : [];
        if (in_array($type, ['single_choice', 'multiple_choice'], true)) {
            $options = $block['options'] ?? null;
            $this->ensure(is_array($options) && array_is_list($options) && count($options) > 0 && count($options) <= 50, 'Choice questions require one to fifty options.');
            $optionIds = [];
            foreach ($options as $option) {
                $this->ensure(is_array($option), 'Every option must be an object.');
                $this->ensureAllowedKeys($option, ['id', 'value', 'label', 'score'], 'Option');
                $optionId = $this->requiredStableId($option['id'] ?? null, 'Every option requires a stable ID.');
                $value = $this->requiredString($option['value'] ?? null, 'Every option requires a machine value.', 255);
                $this->requiredString($option['label'] ?? null, 'Every option requires a display label.', 500);
                if (array_key_exists('score', $option)) {
                    $this->ensure(is_int($option['score']) && $this->isValidScore($option['score']), 'Option score must be an integer between -10000 and 10000.');
                }
                $this->ensure(! isset($optionIds[$optionId]), 'Option IDs must be unique within a question.');
                $this->ensure(! in_array($value, $values, true), 'Option values must be unique within a question.');
                $optionIds[$optionId] = true;
                $values[] = $value;
            }
        } elseif (array_key_exists('options', $block)) {
            $this->ensure(is_array($block['options']) && array_is_list($block['options']) && $block['options'] === [], 'Only choice questions may define options.');
        }

        return ['type' => $type, 'values' => $values];
    }

    private function isValidScore(int $score): bool
    {
        return $score >= -10000 && $score <= 10000;
    }

    /** @param array<string, array{position:int,type:string,values:list<string>}> $questions */
    private function validateCondition(mixed $condition, array $questions, int $position): void
    {
        $this->ensure(is_array($condition) && $condition !== [], 'Visibility must be a condition leaf or a nonempty all/any group.');
        $isAll = array_key_exists('all', $condition);
        $isAny = array_key_exists('any', $condition);
        if ($isAll || $isAny) {
            $this->ensure(! ($isAll && $isAny), 'A condition group cannot contain both all and any.');
            $children = $condition[$isAll ? 'all' : 'any'];
            $this->ensure(is_array($children) && count($children) > 0, 'Condition groups must be nonempty.');
            $this->ensure(count($condition) === 1, 'Condition groups may contain only all or any.');
            foreach ($children as $child) {
                $this->validateCondition($child, $questions, $position);
            }

            return;
        }

        $this->ensure(array_key_exists('question_id', $condition) && array_key_exists('operator', $condition), 'Condition leaves require question_id and operator.');
        $questionId = $this->requiredStableId($condition['question_id'], 'Condition question ID is required.');
        $this->ensure(isset($questions[$questionId]) && $questions[$questionId]['position'] < $position, 'Conditions may reference only earlier questions.');
        $operator = $condition['operator'];
        $this->ensure(is_string($operator) && in_array($operator, self::OPERATORS, true), 'Unsupported visibility operator.');
        $this->ensure(array_diff(array_keys($condition), ['question_id', 'operator', 'value']) === [], 'Condition leaves contain unsupported fields.');

        $source = $questions[$questionId];
        $withoutValue = in_array($operator, ['empty', 'not_empty'], true);
        if ($withoutValue) {
            $this->ensure(! array_key_exists('value', $condition) || $condition['value'] === null || $condition['value'] === '', 'Empty operators do not accept a comparison value.');

            return;
        }
        $this->ensure(array_key_exists('value', $condition), 'This operator requires a comparison value.');
        $value = $condition['value'];
        if (in_array($operator, ['greater_than', 'less_than'], true)) {
            $this->ensure(false, 'Numeric comparison is unsupported for MVP question types.');
        }
        if ($source['type'] === 'multiple_choice') {
            $this->ensure(in_array($operator, ['contains', 'equals', 'not_equals', 'in', 'not_in'], true), 'Operator is incompatible with a multiple-choice question.');
        } else {
            $this->ensure($operator !== 'contains' || in_array($source['type'], ['short_text', 'long_text'], true), 'Contains is only compatible with text or multiple-choice questions.');
        }
        if (in_array($operator, ['in', 'not_in'], true)) {
            $this->ensure(is_array($value) && count($value) > 0 && array_is_list($value), 'In operators require a nonempty list value.');
            foreach ($value as $operand) {
                $this->validateOperand($source, $operand);
            }

            return;
        }
        $this->validateOperand($source, $value);
    }

    /** @param array{position:int,type:string,values:list<string>} $source */
    private function validateOperand(array $source, mixed $value): void
    {
        $this->ensure(is_string($value) || is_int($value) || is_float($value), 'Condition comparison values must be scalar.');
        if ($source['values'] !== []) {
            $this->ensure(in_array((string) $value, $source['values'], true), 'Condition value is not a valid source-question option.');
        }
    }

    /** @param array<string, mixed> $payload
     * @param  list<string>  $allowed
     */
    private function ensureAllowedKeys(array $payload, array $allowed, string $subject): void
    {
        $this->ensure(array_diff(array_keys($payload), $allowed) === [], $subject.' contains unsupported fields.');
    }

    private function requiredStableId(mixed $value, string $message): string
    {
        $id = $this->requiredString($value, $message, 100);
        $this->ensure(preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $id) === 1, $message);

        return $id;
    }

    private function requiredString(mixed $value, string $message, int $maximum): string
    {
        $this->ensure(is_string($value) && trim($value) !== '' && mb_strlen($value) <= $maximum, $message);

        return trim($value);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            $this->fail($message);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['definition' => $message]);
    }
}

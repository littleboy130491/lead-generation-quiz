<?php

namespace App\Domain\Quiz\Validation;

use App\Domain\Quiz\Pagination\QuizPageCompiler;
use Illuminate\Validation\ValidationException;

class QuizDefinitionValidator
{
    private const BLOCK_TYPES = ['question', 'content', 'page_break'];

    private const QUESTION_TYPES = ['single_choice', 'multiple_choice', 'yes_no', 'short_text', 'long_text'];

    private const OPERATORS = ['equals', 'not_equals', 'in', 'not_in', 'contains', 'empty', 'not_empty', 'greater_than', 'less_than'];

    /** Validate the complete persisted schema-version 1 contract. */
    public function validate(array $definition): void
    {
        $this->ensureAllowedKeys($definition, ['schema_version', 'blocks'], 'Definition');
        $this->ensure(($definition['schema_version'] ?? null) === 1, 'Unsupported schema version.');
        $this->ensure(array_key_exists('blocks', $definition) && is_array($definition['blocks']) && array_is_list($definition['blocks']) && count($definition['blocks']) > 0 && count($definition['blocks']) <= 100, 'A definition requires one to one hundred ordered blocks.');

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
                'question' => ['id', 'type', 'question_type', 'label', 'help', 'required', 'max_length', 'options', 'visibility'],
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
        if (array_key_exists('help', $block) && $block['help'] !== null) {
            $this->requiredString($block['help'], 'Question help must be nonempty when present.', 2000);
        }
        if (array_key_exists('max_length', $block)) {
            $maximum = $type === 'short_text' ? 1000 : ($type === 'long_text' ? 10000 : null);
            $this->ensure($maximum !== null && is_int($block['max_length']) && $block['max_length'] >= 1 && $block['max_length'] <= $maximum, 'Max length is only supported for text questions and must be within its type bound.');
        }
        $values = $type === 'yes_no' ? ['yes', 'no'] : [];
        if (in_array($type, ['single_choice', 'multiple_choice'], true)) {
            $options = $block['options'] ?? null;
            $this->ensure(is_array($options) && array_is_list($options) && count($options) > 0 && count($options) <= 50, 'Choice questions require one to fifty options.');
            $optionIds = [];
            foreach ($options as $option) {
                $this->ensure(is_array($option), 'Every option must be an object.');
                $this->ensureAllowedKeys($option, ['id', 'value', 'label'], 'Option');
                $optionId = $this->requiredStableId($option['id'] ?? null, 'Every option requires a stable ID.');
                $value = $this->requiredString($option['value'] ?? null, 'Every option requires a machine value.', 255);
                $this->requiredString($option['label'] ?? null, 'Every option requires a display label.', 500);
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

<?php

namespace App\Ai\Prompt;

final class AnalysisPromptVariables
{
    public const QUESTIONS_AND_ANSWERS = 'questions_and_answers';

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function excludedQuestionIds(array $definition): array
    {
        $ids = [];
        foreach ($definition['blocks'] ?? [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'question') {
                continue;
            }
            if (($block['exclude_from_ai'] ?? false) === true && is_string($block['id'] ?? null) && $block['id'] !== '') {
                $ids[] = $block['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function filterRevision(array $definition): array
    {
        $excluded = array_flip($this->excludedQuestionIds($definition));
        $definition['blocks'] = array_values(array_filter(
            $definition['blocks'] ?? [],
            static function (mixed $block) use ($excluded): bool {
                if (! is_array($block) || ($block['type'] ?? null) !== 'question') {
                    return is_array($block);
                }

                return ! isset($excluded[$block['id'] ?? '']);
            }
        ));

        if (isset($definition['result']) && is_array($definition['result'])) {
            unset($definition['result']['system_prompt']);
            if ($definition['result'] === []) {
                unset($definition['result']);
            }
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function filterAnswers(array $definition, array $answers): array
    {
        $excluded = array_flip($this->excludedQuestionIds($definition));

        return array_filter(
            $answers,
            static fn (mixed $value, mixed $key): bool => is_string($key) && ! isset($excluded[$key]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     */
    public function substitute(string $template, array $definition, array $answers, bool $allowPerQuestion): string
    {
        if ($template === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_.-]+)\s*\}\}/i',
            function (array $matches) use ($definition, $answers, $allowPerQuestion): string {
                $name = strtolower($matches[1]);
                if ($name === self::QUESTIONS_AND_ANSWERS) {
                    return $this->wrapUntrusted('questions_and_answers', $this->formatQuestionsAndAnswers($definition, $answers));
                }

                if ($allowPerQuestion && preg_match('/^(question|answer)\.([a-z0-9_-]+)$/i', $name, $parts) === 1) {
                    $kind = strtolower($parts[1]);
                    $id = $parts[2];
                    if (in_array($id, $this->excludedQuestionIds($definition), true)) {
                        return $this->wrapUntrusted("{$kind}.{$id}", '');
                    }

                    $value = $kind === 'question'
                        ? $this->questionLabel($definition, $id)
                        : $this->formatAnswer($definition, $id, $answers[$id] ?? null);

                    return $this->wrapUntrusted("{$kind}.{$id}", $value);
                }

                return $matches[0];
            },
            $template
        );
    }

    /**
     * @param  list<string>  $questionIds
     * @return list<string>
     */
    public function disallowedPlaceholders(string $template, bool $allowPerQuestion, array $questionIds = []): array
    {
        if ($template === '' || preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', $template, $matches) === 0) {
            return [];
        }

        $allowedIds = array_flip($questionIds);
        $invalid = [];
        foreach ($matches[1] as $raw) {
            $name = strtolower(trim((string) $raw));
            if ($name === self::QUESTIONS_AND_ANSWERS) {
                continue;
            }
            if ($allowPerQuestion && preg_match('/^(question|answer)\.([a-z0-9_-]+)$/i', $name, $parts) === 1) {
                if (isset($allowedIds[$parts[2]])) {
                    continue;
                }
            }
            $invalid[] = trim((string) $raw);
        }

        return array_values(array_unique($invalid));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     */
    public function formatQuestionsAndAnswers(array $definition, array $answers): string
    {
        $lines = [];
        foreach ($definition['blocks'] ?? [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'question') {
                continue;
            }
            if (($block['exclude_from_ai'] ?? false) === true) {
                continue;
            }
            $id = (string) ($block['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $label = trim((string) ($block['label'] ?? $id));
            $answer = $this->formatAnswer($definition, $id, $answers[$id] ?? null);
            $lines[] = "Q ({$id}): {$label}\nA: {$answer}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function questionLabel(array $definition, string $id): string
    {
        foreach ($definition['blocks'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'question' && ($block['id'] ?? null) === $id) {
                return trim((string) ($block['label'] ?? $id));
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function formatAnswer(array $definition, string $id, mixed $answer): string
    {
        if ($answer === null) {
            return '';
        }

        $block = null;
        foreach ($definition['blocks'] ?? [] as $candidate) {
            if (is_array($candidate) && ($candidate['type'] ?? null) === 'question' && ($candidate['id'] ?? null) === $id) {
                $block = $candidate;
                break;
            }
        }

        if (is_array($answer)) {
            $parts = array_map(fn (mixed $value): string => $this->formatScalarAnswer($block, $value), $answer);

            return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        return $this->formatScalarAnswer($block, $answer);
    }

    /**
     * @param  array<string, mixed>|null  $block
     */
    private function formatScalarAnswer(?array $block, mixed $answer): string
    {
        if (is_bool($answer)) {
            return $answer ? 'true' : 'false';
        }
        if (is_int($answer) || is_float($answer)) {
            return (string) $answer;
        }
        if (! is_string($answer)) {
            return '';
        }

        $value = $answer;
        if (is_array($block) && in_array($block['question_type'] ?? null, ['single_choice', 'multiple_choice'], true)) {
            foreach ($block['options'] ?? [] as $option) {
                if (is_array($option) && ($option['value'] ?? null) === $value) {
                    return trim((string) ($option['label'] ?? $value));
                }
            }
        }

        if (is_array($block) && ($block['question_type'] ?? null) === 'yes_no') {
            return match (strtolower($value)) {
                'yes' => 'Yes',
                'no' => 'No',
                default => $value,
            };
        }

        return $value;
    }

    private function wrapUntrusted(string $id, string $value): string
    {
        $safeId = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<untrusted_prompt_data id=\"{$safeId}\">{$value}</untrusted_prompt_data>";
    }
}

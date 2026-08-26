<?php

namespace App\Domain\Quiz\Scoring;

use App\Domain\Quiz\Conditions\ConditionEvaluator;

final class QuizScoreCalculator
{
    public function __construct(private ConditionEvaluator $conditions) {}

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @return array{total: int, result: array{id: string, title: string, html: ?string}|null}|null
     */
    public function calculate(array $definition, array $answers): ?array
    {
        if (! $this->definitionUsesScoring($definition)) {
            return null;
        }

        $total = 0;
        foreach ($definition['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'question') {
                continue;
            }
            if (! $this->conditions->visible($block['visibility'] ?? null, $answers)) {
                continue;
            }

            $total += $this->scoreForAnswer($block, $answers[$block['id'] ?? ''] ?? null);
        }

        $result = null;
        foreach ($definition['score_results'] ?? [] as $band) {
            if (! is_array($band)) {
                continue;
            }
            $min = $band['min_score'] ?? null;
            $max = $band['max_score'] ?? null;
            if (! is_int($min) || ! is_int($max) || $total < $min || $total > $max) {
                continue;
            }

            $result = [
                'id' => (string) $band['id'],
                'title' => (string) $band['title'],
                'html' => array_key_exists('html', $band) && is_string($band['html']) ? $band['html'] : null,
            ];
            break;
        }

        return ['total' => $total, 'result' => $result];
    }

    /** @param  array<string, mixed>  $definition */
    private function definitionUsesScoring(array $definition): bool
    {
        if (! empty($definition['score_results']) && is_array($definition['score_results'])) {
            return true;
        }

        foreach ($definition['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'question') {
                continue;
            }
            if (array_key_exists('yes_score', $block) || array_key_exists('no_score', $block)) {
                return true;
            }
            foreach ($block['options'] ?? [] as $option) {
                if (is_array($option) && array_key_exists('score', $option)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $block */
    private function scoreForAnswer(array $block, mixed $answer): int
    {
        $type = $block['question_type'] ?? null;
        if ($type === 'yes_no') {
            if ($answer === 'yes') {
                return (int) ($block['yes_score'] ?? 0);
            }
            if ($answer === 'no') {
                return (int) ($block['no_score'] ?? 0);
            }

            return 0;
        }

        if ($type === 'single_choice' && is_string($answer)) {
            return $this->optionScore($block['options'] ?? [], $answer);
        }

        if ($type === 'multiple_choice' && is_array($answer)) {
            $total = 0;
            foreach ($answer as $value) {
                if (is_string($value)) {
                    $total += $this->optionScore($block['options'] ?? [], $value);
                }
            }

            return $total;
        }

        return 0;
    }

    /** @param  list<mixed>  $options */
    private function optionScore(array $options, string $value): int
    {
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            $optionValue = (string) ($option['value'] ?? $option['id'] ?? '');
            if ($optionValue === $value) {
                return (int) ($option['score'] ?? 0);
            }
        }

        return 0;
    }
}

<?php

namespace App\Domain\Quiz\Conditions;

use InvalidArgumentException;

class ConditionEvaluator
{
    public function visible(?array $condition, array $answers): bool
    {
        if (! $condition) {
            return true;
        } if (isset($condition['all'])) {
            return collect($condition['all'])->every(fn ($c) => $this->visible($c, $answers));
        } if (isset($condition['any'])) {
            return collect($condition['any'])->contains(fn ($c) => $this->visible($c, $answers));
        } $value = $answers[$condition['question_id'] ?? ''] ?? null;
        $expected = $condition['value'] ?? null;

        return match ($condition['operator'] ?? '') {
            'equals' => $value === $expected,'not_equals' => $value !== $expected,'in' => in_array($value, (array) $expected, true),'not_in' => ! in_array($value, (array) $expected, true),'contains' => is_array($value) ? in_array($expected, $value, true) : str_contains((string) $value, (string) $expected),'empty' => $value === null || $value === '' || $value === [],'not_empty' => ! ($value === null || $value === '' || $value === []),'greater_than' => is_numeric($value) && $value > $expected,'less_than' => is_numeric($value) && $value < $expected,default => throw new InvalidArgumentException('Unsupported visibility operator.')
        };
    }
}

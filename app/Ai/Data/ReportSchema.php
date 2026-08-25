<?php

namespace App\Ai\Data;

use InvalidArgumentException;

final class ReportSchema
{
    public const VERSION = '1';

    public static function validate(array $report): array
    {
        foreach (['executive_summary', 'profile', 'disclaimer'] as $field) {
            if (! is_string($report[$field] ?? null) || trim($report[$field]) === '') {
                throw new InvalidArgumentException("Report field [{$field}] must be a non-empty string.");
            }
        }
        foreach (['strengths', 'challenges', 'recommendations', 'action_plan'] as $field) {
            if (! is_array($report[$field] ?? null)) {
                throw new InvalidArgumentException("Report field [{$field}] must be an array.");
            }
            foreach ($report[$field] as $item) {
                if (! is_array($item) || ! is_string($item['title'] ?? null) || ! is_string($item['detail'] ?? null)) {
                    throw new InvalidArgumentException("Every [{$field}] item requires title and detail strings.");
                }
            }
        }

        return $report + ['schema_version' => self::VERSION];
    }

    public static function example(): array
    {
        return ['schema_version' => self::VERSION, 'executive_summary' => 'A concise diagnosis.', 'profile' => 'A practical profile.', 'strengths' => [['title' => 'Strength', 'detail' => 'Build on it.']], 'challenges' => [['title' => 'Challenge', 'detail' => 'Address it.']], 'recommendations' => [['title' => 'Recommendation', 'detail' => 'Take this action.']], 'action_plan' => [['title' => 'Week one', 'detail' => 'Start here.']], 'disclaimer' => 'Educational guidance only.'];
    }
}

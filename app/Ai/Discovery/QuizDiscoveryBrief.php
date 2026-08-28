<?php

namespace App\Ai\Discovery;

final class QuizDiscoveryBrief
{
    /** @var array<string, int> */
    private const FIELDS = [
        'business_context' => 4000,
        'target_audience' => 500,
        'objective' => 500,
        'desired_insight' => 500,
        'question_count' => 2,
        'tone' => 100,
    ];

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $candidate
     * @return array<string, int|string>
     */
    public static function merge(array $current, array $candidate): array
    {
        $brief = [];

        foreach (self::FIELDS as $field => $maximum) {
            if ($field === 'question_count') {
                $questionCount = self::questionCount($candidate[$field] ?? null)
                    ?? self::questionCount($current[$field] ?? null);

                if ($questionCount !== null) {
                    $brief[$field] = $questionCount;
                }

                continue;
            }

            $value = $candidate[$field] ?? $current[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
            $text = preg_replace('/<\?(?:php|=)?|\{\{\s*|javascript\s*:/iu', '', $text) ?? '';
            if ($text !== '') {
                $brief[$field] = mb_substr($text, 0, $maximum);
            }
        }

        return $brief;
    }

    private static function questionCount(mixed $value): ?int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $count = (int) $value;

        return $count >= 1 && $count <= 30 ? $count : null;
    }

    /** @param array<string, mixed> $brief */
    public static function nextMissingField(array $brief): ?string
    {
        foreach (['business_context', 'objective', 'target_audience', 'desired_insight'] as $field) {
            if (blank($brief[$field] ?? null)) {
                return $field;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $brief */
    public static function isReady(array $brief): bool
    {
        return self::nextMissingField($brief) === null;
    }

    /** @param array<string, mixed> $brief */
    public static function hasEnoughContext(array $brief): bool
    {
        return filled($brief['business_context'] ?? null) || filled($brief['objective'] ?? null);
    }
}

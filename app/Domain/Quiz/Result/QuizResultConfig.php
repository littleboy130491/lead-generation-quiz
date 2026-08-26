<?php

namespace App\Domain\Quiz\Result;

use App\Enums\QuizResultMode;

final class QuizResultConfig
{
    /** @param  array<string, mixed>  $definition */
    public static function mode(array $definition): QuizResultMode
    {
        $mode = data_get($definition, 'result.mode', QuizResultMode::Ai->value);

        return QuizResultMode::tryFrom(is_string($mode) ? $mode : '') ?? QuizResultMode::Ai;
    }

    /** @param  array<string, mixed>  $definition */
    public static function usesScoreResults(array $definition): bool
    {
        return self::mode($definition) === QuizResultMode::Score;
    }

    /** @param  array<string, mixed>  $definition */
    public static function thankYouEnabled(array $definition): bool
    {
        if (self::mode($definition) === QuizResultMode::Ai) {
            return true;
        }

        if (! array_key_exists('thank_you', $definition) || ! is_array($definition['thank_you'])) {
            return true;
        }

        if (! array_key_exists('enabled', $definition['thank_you'])) {
            return true;
        }

        return (bool) $definition['thank_you']['enabled'];
    }

    /** @param  array<string, mixed>  $definition */
    public static function thankYouOverrideHtml(array $definition): ?string
    {
        $html = data_get($definition, 'thank_you.html');
        if (! is_string($html)) {
            return null;
        }
        $html = trim($html);

        return $html === '' ? null : $html;
    }
}

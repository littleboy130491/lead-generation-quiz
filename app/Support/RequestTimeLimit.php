<?php

namespace App\Support;

/**
 * Synchronous AI calls can outlive the default PHP-FPM execution limit, which
 * kills the request after the provider has already been billed. Web requests
 * that wait on a provider raise their own limit to the configured AI timeout
 * plus room for validation and persistence.
 */
final class RequestTimeLimit
{
    public const OVERHEAD_SECONDS = 15;

    public static function extendForAiCall(int $timeoutSeconds, int $attempts = 1): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $required = ($timeoutSeconds * max($attempts, 1)) + self::OVERHEAD_SECONDS;
        $current = (int) ini_get('max_execution_time');

        if ($current !== 0 && $current < $required) {
            @set_time_limit($required);
        }
    }
}

<?php

namespace App\Ai\Discovery;

final class QuizDiscoveryIntent
{
    public static function wantsExecute(string $message): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($message)) ?? ''));

        if ($normalized === '') {
            return false;
        }

        if (preg_match(
            '/\b(execute\s+now|generate\s+(?:it|now|the\s+draft|a\s+draft|the\s+quiz|a\s+quiz)|create\s+(?:it|now|the\s+draft|a\s+draft|the\s+quiz|a\s+quiz)|build\s+(?:it|now|the\s+quiz|a\s+quiz)|go\s+ahead|do\s+it\s+now|make\s+the\s+quiz)\b/u',
            $normalized,
        )) {
            return true;
        }

        return (bool) preg_match('/^(?:please\s+)?(?:execute|generate|create|build)(?:\s+now)?[.!]*$/u', $normalized);
    }

    public static function isControl(string $message): bool
    {
        return self::wantsExecute($message);
    }
}

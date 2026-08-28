<?php

namespace App\Ai\Discovery;

final class QuizDiscoveryIntent
{
    /** @var list<string> */
    private const COMMANDS = [
        'execute now',
        'generate now',
        'create now',
        'build now',
        'generate the quiz',
        'create the quiz',
        'build the quiz',
        'make the quiz',
        'generate the draft',
        'create the draft',
        'generate it',
        'create it now',
        'build it now',
        'update now',
        'update the quiz',
        'update the quiz now',
        'replace the draft',
        'replace the quiz draft',
        'create the quiz now',
        'generate the quiz now',
        'go ahead',
        "that's enough",
        'thats enough',
        "i'm done",
        'im done',
        'i am done',
        'skip the rest',
        'skip the interview',
    ];

    public static function wantsImmediateGeneration(string $message): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? ''));
        $normalized = trim($normalized, " \t\n\r\0\x0B.,!");

        if ($normalized === '') {
            return false;
        }

        if (str_starts_with($normalized, 'please ')) {
            $normalized = trim(substr($normalized, 7));
        }

        return in_array($normalized, self::COMMANDS, true);
    }
}

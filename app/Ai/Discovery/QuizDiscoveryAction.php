<?php

namespace App\Ai\Discovery;

enum QuizDiscoveryAction: string
{
    case Continue = 'continue';
    case Ready = 'ready';
    case Execute = 'execute';

    public static function fromMixed(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? strtolower(trim($value)) : '') ?? self::Continue;
    }
}

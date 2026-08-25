<?php

namespace App\Ai;

use RuntimeException;

class GenerationException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly array $attempts = [])
    {
        parent::__construct($message);
    }
}

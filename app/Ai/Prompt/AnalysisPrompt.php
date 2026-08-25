<?php

namespace App\Ai\Prompt;

final readonly class AnalysisPrompt
{
    public function __construct(public string $system, public string $user) {}
}

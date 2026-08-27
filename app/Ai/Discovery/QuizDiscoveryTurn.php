<?php

namespace App\Ai\Discovery;

use App\Models\QuizDiscoverySession;

final class QuizDiscoveryTurn
{
    public function __construct(
        public QuizDiscoverySession $session,
        public QuizDiscoveryAction $action,
    ) {}
}

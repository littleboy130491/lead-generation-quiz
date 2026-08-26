<?php

namespace App\Ai\Discovery;

interface QuizDiscoveryInterviewer
{
    /**
     * @param  array<string, mixed>  $brief
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{message: string, brief: array<string, int|string>}
     */
    public function respond(array $brief, array $messages, string $systemPrompt): array;
}

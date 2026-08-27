<?php

namespace App\Ai\Discovery;

interface QuizDiscoveryInterviewer
{
    /**
     * @param  array<string, mixed>  $brief
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{message: string, brief: array<string, int|string>, ready_to_generate: bool}
     */
    public function respond(array $brief, array $messages, string $systemPrompt): array;
}

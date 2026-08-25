<?php

namespace App\Ai\Contracts;

interface QuizDefinitionGenerator
{
    /**
     * @param  array<string, mixed>  $brief
     * @param  list<array{provider: string, model: string}>  $providerChain
     * @return array<string, mixed>
     */
    public function generate(array $brief, array $providerChain, string $systemPrompt): array;
}

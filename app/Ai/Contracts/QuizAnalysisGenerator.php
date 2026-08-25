<?php

namespace App\Ai\Contracts;

interface QuizAnalysisGenerator
{
    /** @return array{result:array,provider:string,model:string,attempts:array} */
    public function generate(array $revision, array $answers, array $chain, string $systemPrompt): array;
}

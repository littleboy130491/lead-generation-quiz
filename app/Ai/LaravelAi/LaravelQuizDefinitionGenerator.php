<?php

namespace App\Ai\LaravelAi;

use App\Ai\ConfiguredAiProviders;
use App\Ai\Contracts\QuizDefinitionGenerator;
use App\Ai\Data\QuizDefinitionJsonSchema;
use App\Ai\GenerationException;
use App\Ai\HeuristicQuizDefinitionGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Enums\Lab;

class LaravelQuizDefinitionGenerator implements QuizDefinitionGenerator
{
    public function __construct(
        private ConfiguredAiProviders $configured,
        private HeuristicQuizDefinitionGenerator $heuristic,
    ) {}

    public function generate(array $brief, array $providerChain, string $systemPrompt): array
    {
        $usable = $this->configured->usable($providerChain);
        if ($usable === []) {
            return $this->heuristic->generate($brief);
        }

        $attempts = [];
        foreach ($usable as $entry) {
            try {
                $this->configured->applyRuntimeConfig($entry);
                $response = \Laravel\Ai\agent(
                    instructions: $systemPrompt,
                    schema: fn (JsonSchema $schema) => QuizDefinitionJsonSchema::definition($schema),
                )->prompt('<untrusted_administrator_brief>'.json_encode($brief, JSON_THROW_ON_ERROR).'</untrusted_administrator_brief>', provider: Lab::from($entry['provider']), model: $entry['model'], timeout: 60);

                return $response->toArray();
            } catch (\Throwable $exception) {
                $attempts[] = [
                    'provider' => $entry['provider'],
                    'model' => $entry['model'],
                    'status' => 'failed',
                    'code' => 'provider_failure',
                    'message' => str($exception->getMessage())->limit(500)->toString(),
                ];
            }
        }

        throw new GenerationException('ai_generation_failed', 'All configured AI providers failed.', $attempts);
    }
}

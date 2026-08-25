<?php

namespace App\Ai\LaravelAi;

use App\Ai\Contracts\QuizDefinitionGenerator;
use App\Ai\GenerationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Enums\Lab;

class LaravelQuizDefinitionGenerator implements QuizDefinitionGenerator
{
    public function generate(array $brief, array $providerChain, string $systemPrompt): array
    {
        foreach ($providerChain as $entry) {
            $provider = $entry['provider'] ?? null;
            $model = $entry['model'] ?? null;
            if (! is_string($provider) || ! is_string($model) || ! config("ai.providers.{$provider}.key")) {
                continue;
            }
            try {
                $response = \Laravel\Ai\agent(
                    instructions: $systemPrompt,
                    schema: fn (JsonSchema $schema) => ['schema_version' => $schema->integer()->required(), 'blocks' => $schema->array()->items($schema->object())->required()],
                )->prompt('<untrusted_administrator_brief>'.json_encode($brief, JSON_THROW_ON_ERROR).'</untrusted_administrator_brief>', provider: Lab::from($provider), model: $model, timeout: 60);

                return $response->toArray();
            } catch (\Throwable) {
                continue;
            }
        }

        throw new GenerationException('ai_unavailable', 'No configured AI provider credentials are available.');
    }
}

<?php

namespace App\Ai\LaravelAi;

use App\Ai\ConfiguredAiProviders;
use App\Ai\Contracts\QuizDefinitionGenerator;
use App\Ai\GenerationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Enums\Lab;

class LaravelQuizDefinitionGenerator implements QuizDefinitionGenerator
{
    public function __construct(private ConfiguredAiProviders $configured) {}

    public function generate(array $brief, array $providerChain, string $systemPrompt): array
    {
        foreach ($this->configured->usable($providerChain) as $entry) {
            try {
                $response = \Laravel\Ai\agent(
                    instructions: $systemPrompt,
                    schema: fn (JsonSchema $schema) => ['schema_version' => $schema->integer()->required(), 'blocks' => $schema->array()->items($schema->object())->required()],
                )->prompt('<untrusted_administrator_brief>'.json_encode($brief, JSON_THROW_ON_ERROR).'</untrusted_administrator_brief>', provider: Lab::from($entry['provider']), model: $entry['model'], timeout: 60);

                return $response->toArray();
            } catch (\Throwable) {
                continue;
            }
        }

        throw new GenerationException('ai_unavailable', ConfiguredAiProviders::UNAVAILABLE_MESSAGE);
    }
}

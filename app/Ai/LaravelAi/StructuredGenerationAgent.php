<?php

namespace App\Ai\LaravelAi;

use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\StructuredAnonymousAgent;

/**
 * Structured-output agent for the application's synchronous generation calls.
 *
 * Reasoning models spend hidden tokens before writing a response, which pushed
 * quiz-definition generation past its request timeout even though the schema and
 * prompt were sound. These calls produce a schema-constrained object rather than
 * open-ended prose, so extended reasoning buys little and costs the whole
 * latency budget. Providers that support toggling it are asked to turn it off.
 */
class StructuredGenerationAgent extends StructuredAnonymousAgent implements HasProviderOptions
{
    public function providerOptions(Lab|string $provider): array
    {
        if (! config('ai_agents.disable_reasoning')) {
            return [];
        }

        return match ($provider instanceof Lab ? $provider->value : $provider) {
            Lab::OpenRouter->value => ['reasoning' => ['enabled' => false]],
            default => [],
        };
    }

    public function maxTokens(): ?int
    {
        $maximum = (int) config('ai_agents.max_output_tokens');

        return $maximum > 0 ? $maximum : null;
    }
}

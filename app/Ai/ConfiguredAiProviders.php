<?php

namespace App\Ai;

class ConfiguredAiProviders
{
    public const UNAVAILABLE_MESSAGE = 'No configured AI provider credentials are available.';

    public const UNAVAILABLE_ADMIN_GUIDANCE = 'No configured AI provider credentials are available. Add a quiz AI provider chain in Operational settings and set the matching environment keys.';

    /**
     * @param  list<array{provider?: mixed, model?: mixed}>  $chain
     */
    public function hasUsableCredentials(array $chain): bool
    {
        return $this->usable($chain) !== [];
    }

    /**
     * @param  list<array{provider?: mixed, model?: mixed}>  $chain
     * @return list<array{provider: string, model: string}>
     */
    public function usable(array $chain): array
    {
        $usable = [];
        foreach ($chain as $entry) {
            $provider = $entry['provider'] ?? null;
            $model = $entry['model'] ?? null;
            if (! is_string($provider) || ! is_string($model) || ! config("ai.providers.{$provider}.key")) {
                continue;
            }
            $usable[] = ['provider' => $provider, 'model' => $model];
        }

        return $usable;
    }
}

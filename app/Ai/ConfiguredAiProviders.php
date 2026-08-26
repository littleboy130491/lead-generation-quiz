<?php

namespace App\Ai;

class ConfiguredAiProviders
{
    public const UNAVAILABLE_MESSAGE = 'No configured AI provider credentials are available.';

    public const SCAFFOLD_ADMIN_GUIDANCE = 'No quiz AI provider credentials are configured. Confirm will create a structural draft from this brief that you can edit. Add a Quiz AI provider chain in Operational settings and matching environment keys for model-written drafts.';

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

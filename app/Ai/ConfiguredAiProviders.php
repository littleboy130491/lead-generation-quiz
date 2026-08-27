<?php

namespace App\Ai;

class ConfiguredAiProviders
{
    public const UNAVAILABLE_MESSAGE = 'No configured AI provider credentials are available.';

    /**
     * @param  list<array{provider?: mixed, model?: mixed, endpoint_url?: mixed}>  $chain
     */
    public function hasUsableCredentials(array $chain): bool
    {
        return $this->usable($chain) !== [];
    }

    /**
     * @param  list<array{provider?: mixed, model?: mixed, endpoint_url?: mixed}>  $chain
     * @return list<array{provider: string, model: string, endpoint_url?: string}>
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
            if ($provider === 'openai-compatible') {
                $url = $entry['endpoint_url'] ?? config('ai.providers.openai-compatible.url');
                if (! is_string($url) || trim($url) === '') {
                    continue;
                }
            }

            $usableEntry = ['provider' => $provider, 'model' => $model];
            if (is_string($entry['endpoint_url'] ?? null) && trim((string) $entry['endpoint_url']) !== '') {
                $usableEntry['endpoint_url'] = trim((string) $entry['endpoint_url']);
            }
            $usable[] = $usableEntry;
        }

        return $usable;
    }

    /**
     * @param  array{provider: string, model: string, endpoint_url?: string}  $entry
     */
    public function applyRuntimeConfig(array $entry): void
    {
        if (($entry['provider'] ?? null) === 'openai-compatible' && filled($entry['endpoint_url'] ?? null)) {
            config(['ai.providers.openai-compatible.url' => (string) $entry['endpoint_url']]);
        }
    }
}

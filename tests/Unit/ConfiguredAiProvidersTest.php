<?php

namespace Tests\Unit;

use App\Ai\ConfiguredAiProviders;
use Tests\TestCase;

class ConfiguredAiProvidersTest extends TestCase
{
    public function test_empty_chain_has_no_usable_credentials(): void
    {
        config(['ai.providers.openai.key' => 'sk-test']);

        $this->assertFalse(app(ConfiguredAiProviders::class)->hasUsableCredentials([]));
    }

    public function test_chain_entry_without_provider_key_is_not_usable(): void
    {
        config(['ai.providers.openai.key' => null]);

        $this->assertFalse(app(ConfiguredAiProviders::class)->hasUsableCredentials([
            ['provider' => 'openai', 'model' => 'gpt-test'],
        ]));
    }

    public function test_chain_entry_with_provider_and_key_is_usable(): void
    {
        config(['ai.providers.openai.key' => 'sk-test']);

        $this->assertTrue(app(ConfiguredAiProviders::class)->hasUsableCredentials([
            ['provider' => 'openai', 'model' => 'gpt-test'],
        ]));
    }

    public function test_custom_openai_compatible_entry_requires_endpoint_url_or_config_url(): void
    {
        config([
            'ai.providers.openai-compatible.key' => 'sk-compat',
            'ai.providers.openai-compatible.url' => null,
        ]);

        $this->assertFalse(app(ConfiguredAiProviders::class)->hasUsableCredentials([
            ['provider' => 'openai-compatible', 'model' => 'gpt-test'],
        ]));

        $this->assertTrue(app(ConfiguredAiProviders::class)->hasUsableCredentials([
            ['provider' => 'openai-compatible', 'model' => 'gpt-test', 'endpoint_url' => 'https://gateway.example/v1'],
        ]));

        config(['ai.providers.openai-compatible.url' => 'https://env-gateway.example/v1']);
        $this->assertTrue(app(ConfiguredAiProviders::class)->hasUsableCredentials([
            ['provider' => 'openai-compatible', 'model' => 'gpt-test'],
        ]));
    }

    public function test_apply_runtime_config_sets_custom_endpoint_url(): void
    {
        app(ConfiguredAiProviders::class)->applyRuntimeConfig([
            'provider' => 'openai-compatible',
            'model' => 'gpt-test',
            'endpoint_url' => 'https://runtime.example/v1',
        ]);

        $this->assertSame('https://runtime.example/v1', config('ai.providers.openai-compatible.url'));
    }
}

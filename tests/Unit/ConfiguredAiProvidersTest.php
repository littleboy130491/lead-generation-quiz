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
}

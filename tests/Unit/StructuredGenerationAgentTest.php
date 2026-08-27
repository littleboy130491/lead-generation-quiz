<?php

namespace Tests\Unit;

use App\Ai\LaravelAi\StructuredGenerationAgent;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

class StructuredGenerationAgentTest extends TestCase
{
    public function test_reasoning_is_disabled_for_openrouter(): void
    {
        $this->assertSame(
            ['reasoning' => ['enabled' => false]],
            $this->agent()->providerOptions(Lab::OpenRouter),
        );
    }

    public function test_providers_without_a_reasoning_toggle_receive_no_extra_options(): void
    {
        $this->assertSame([], $this->agent()->providerOptions(Lab::OpenAI));
        $this->assertSame([], $this->agent()->providerOptions(Lab::Anthropic));
    }

    public function test_the_reasoning_toggle_can_be_turned_off(): void
    {
        config(['ai_agents.disable_reasoning' => false]);

        $this->assertSame([], $this->agent()->providerOptions(Lab::OpenRouter));
    }

    public function test_generated_output_is_bounded(): void
    {
        config(['ai_agents.max_output_tokens' => 16000]);
        $this->assertSame(16000, $this->agent()->maxTokens());

        config(['ai_agents.max_output_tokens' => 0]);
        $this->assertNull($this->agent()->maxTokens());
    }

    private function agent(): StructuredGenerationAgent
    {
        return new StructuredGenerationAgent('instructions', [], [], fn () => []);
    }
}

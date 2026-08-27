<?php

namespace Tests\Unit;

use App\Ai\Debug\AiDebugLogger;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Mockery;
use Tests\TestCase;

class AiDebugLoggerTest extends TestCase
{
    public function test_it_is_disabled_by_default(): void
    {
        $this->assertFalse(AiDebugLogger::enabled());
    }

    public function test_a_completed_step_records_timing_tokens_and_finish_reason_without_content(): void
    {
        config(['ai_debug.log_content' => false, 'ai_debug.channel' => 'ai']);
        $context = $this->captureContext(fn () => (new AiDebugLogger)->handleStepCompleted($this->stepCompleted()));

        $this->assertSame(1234.0, $context['duration_ms']);
        $this->assertSame('length', $context['finish_reason']);
        $this->assertSame(900, $context['usage']['prompt_tokens']);
        $this->assertSame(7000, $context['usage']['completion_tokens']);
        $this->assertArrayNotHasKey('response', $context);
    }

    public function test_response_bodies_are_recorded_only_when_content_logging_is_enabled(): void
    {
        config(['ai_debug.log_content' => true, 'ai_debug.channel' => 'ai', 'ai_debug.max_content_characters' => 4000]);
        $context = $this->captureContext(fn () => (new AiDebugLogger)->handleStepCompleted($this->stepCompleted()));

        $this->assertSame('{"schema_version":1}', $context['response']);
    }

    /** @return array<string, mixed> */
    private function captureContext(callable $run): array
    {
        $context = [];
        $logger = Mockery::mock();
        $logger->shouldReceive('debug')->once()->andReturnUsing(function (string $message, array $received) use (&$context): void {
            $context = $received;
        });
        Log::shouldReceive('channel')->with('ai')->andReturn($logger);

        $run();

        return $context;
    }

    private function stepCompleted(): StepCompleted
    {
        $response = new StepResponse(
            text: '{"schema_version":1}',
            toolCalls: [],
            finishReason: FinishReason::Length,
            usage: new Usage(promptTokens: 900, completionTokens: 7000),
            meta: new Meta(provider: 'openrouter', model: 'test-model'),
            structured: ['schema_version' => 1],
        );

        return new StepCompleted(
            invocationId: 'inv-1',
            stepNumber: 1,
            agent: Mockery::mock(Agent::class),
            provider: Mockery::mock(TextProvider::class),
            model: 'test-model',
            isFinalStep: true,
            response: $response,
            time: 1234.4,
        );
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Quizzes\RunQuizDiscovery;
use App\Ai\Discovery\LaravelQuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_discovery_interviewer_uses_the_ai_adapter_with_a_safe_fallback(): void
    {
        $this->assertInstanceOf(LaravelQuizDiscoveryInterviewer::class, app(QuizDiscoveryInterviewer::class));
    }

    public function test_an_administrator_can_start_a_discovery_interview_that_persists_a_sanitized_brief_and_assistant_question(): void
    {
        $session = app(RunQuizDiscovery::class)->start(
            User::factory()->create()->id,
            '<b>I need a lead quiz</b> for my consulting business.',
        );

        $this->assertSame('interviewing', $session->status);
        $this->assertSame('I need a lead quiz for my consulting business.', $session->brief['business_context']);
        $this->assertSame(['user', 'assistant'], $session->messages()->pluck('role')->all());
        $this->assertStringContainsString('outcome', $session->messages()->latest('id')->value('content'));
    }
}

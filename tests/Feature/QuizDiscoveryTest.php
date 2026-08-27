<?php

namespace Tests\Feature;

use App\Actions\Quizzes\RunQuizDiscovery;
use App\Ai\Discovery\LaravelQuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Livewire\QuizDiscoveryChat;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
        $result = app(RunQuizDiscovery::class)->start(
            User::factory()->create()->id,
            '<b>I need a lead quiz</b> for my consulting business.',
        );

        $session = $result['session'];
        $this->assertSame('interviewing', $session->status);
        $this->assertSame('I need a lead quiz for my consulting business.', $session->brief['business_context']);
        $this->assertSame(['user', 'assistant'], $session->messages()->pluck('role')->all());
        $this->assertStringContainsString('outcome', $session->messages()->latest('id')->value('content'));
        $this->assertFalse($result['ready_to_generate']);
    }

    public function test_execute_now_marks_the_brief_ready_for_generation_when_core_fields_are_complete(): void
    {
        $discovery = app(RunQuizDiscovery::class);
        $session = $discovery->start(
            User::factory()->create()->id,
            'We help agencies improve client onboarding.',
        )['session'];

        foreach ([
            'Help agency owners see where onboarding is breaking down.',
            'Agency owners with 5-20 clients who feel delivery is chaotic.',
            'A practical onboarding maturity score and next-step recommendation.',
        ] as $reply) {
            $session = $discovery->reply($session, $reply)['session'];
        }

        $this->assertTrue(QuizDiscoveryBrief::isReady($session->brief));

        $result = $discovery->reply($session, 'execute now');
        $this->assertTrue($result['execute_now']);
        $this->assertTrue($result['ready_to_generate']);
    }

    public function test_execute_now_phrases_are_detected(): void
    {
        $this->assertTrue(QuizDiscoveryBrief::wantsToExecute('execute now'));
        $this->assertTrue(QuizDiscoveryBrief::wantsToExecute('Please generate the quiz'));
        $this->assertFalse(QuizDiscoveryBrief::wantsToExecute('We sell consulting services'));
    }

    public function test_discovery_chat_generates_into_the_existing_quiz_when_a_quiz_id_is_provided(): void
    {
        config(['ai.providers.openai.key' => null]);
        $user = User::factory()->create();
        $quiz = Quiz::factory()->create([
            'draft_definition' => ['schema_version' => 1, 'blocks' => []],
        ]);
        $discovery = app(RunQuizDiscovery::class);
        $session = $discovery->start($user->id, 'We sell operations consulting to service firms.')['session'];

        foreach ([
            'Identify operational bottlenecks for owners.',
            'Owners of 10-50 person service businesses.',
            'A prioritized next operational action.',
        ] as $reply) {
            $session = $discovery->reply($session, $reply)['session'];
        }

        Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class, ['quizId' => $quiz->id])
            ->set('sessionId', $session->id)
            ->set('brief', $session->brief)
            ->call('generateDraft')
            ->assertRedirect(route('filament.admin.resources.quizzes.edit', ['record' => $quiz], false));

        $this->assertSame(1, Quiz::count());
        $this->assertNotEmpty($quiz->fresh()->draft_definition['blocks'] ?? []);
    }

    public function test_discovery_chat_opens_brief_review_after_execute_now_in_chat(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'We help agencies improve client onboarding.')
            ->call('sendReply', 'Help agency owners see where onboarding is breaking down.')
            ->call('sendReply', 'Agency owners with 5-20 clients who feel delivery is chaotic.')
            ->call('sendReply', 'A practical onboarding maturity score and next-step recommendation.')
            ->call('sendReply', 'execute now')
            ->assertSet('showBrief', true);
    }
}

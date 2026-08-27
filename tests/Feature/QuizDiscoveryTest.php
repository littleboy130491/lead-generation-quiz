<?php

namespace Tests\Feature;

use App\Actions\Quizzes\RunQuizDiscovery;
use App\Ai\Discovery\LaravelQuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryAction;
use App\Ai\Discovery\QuizDiscoveryBrief;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Livewire\QuizDiscoveryChat;
use App\Models\Quiz;
use App\Models\User;
use App\Settings\ApplicationSettings;
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
        $turn = app(RunQuizDiscovery::class)->start(
            User::factory()->create()->id,
            '<b>I need a lead quiz</b> for my consulting business.',
        );

        $session = $turn->session;
        $this->assertSame(QuizDiscoveryAction::Continue, $turn->action);
        $this->assertSame('interviewing', $session->status);
        $this->assertSame('I need a lead quiz for my consulting business.', $session->brief['business_context']);
        $this->assertSame(['user', 'assistant'], $session->messages()->pluck('role')->all());
        $this->assertStringContainsString('outcome', $session->messages()->latest('id')->value('content'));
    }

    public function test_execute_now_does_not_pollute_brief_fields_and_signals_execute_when_ready(): void
    {
        $discovery = app(RunQuizDiscovery::class);
        $userId = User::factory()->create()->id;

        $turn = $discovery->start($userId, 'We sell ops coaching for indie SaaS founders.');
        $turn = $discovery->reply($turn->session, 'Help founders find their biggest delivery bottleneck.');
        $turn = $discovery->reply($turn->session, 'Indie SaaS founders who feel stuck in delivery chaos.');
        $turn = $discovery->reply($turn->session, 'A clear next operational focus they can act on this week.');

        $this->assertTrue(QuizDiscoveryBrief::isReady($turn->session->brief));
        $this->assertSame(QuizDiscoveryAction::Ready, $turn->action);

        $execute = $discovery->reply($turn->session->fresh(['messages']), 'execute now');

        $this->assertSame(QuizDiscoveryAction::Execute, $execute->action);
        $this->assertTrue(QuizDiscoveryBrief::isReady($execute->session->brief));
        foreach ($execute->session->brief as $value) {
            $this->assertStringNotContainsStringIgnoringCase('execute now', (string) $value);
        }
    }

    public function test_chat_execute_now_generates_a_quiz_draft_from_the_structured_brief(): void
    {
        config(['ai.providers.openai.key' => null]);
        app(ApplicationSettings::class)->put('ai.quiz', [['provider' => 'openai', 'model' => 'gpt-test']]);

        $user = User::factory()->create();
        $discovery = app(RunQuizDiscovery::class);
        $turn = $discovery->start($user->id, 'Leadership coaching for independent consultants.');
        $turn = $discovery->reply($turn->session, 'Identify their current business bottleneck.');
        $turn = $discovery->reply($turn->session, 'Independent consultants who feel stuck.');
        $turn = $discovery->reply($turn->session, 'The next practical action they should take.');

        Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->assertSet('sessionId', $turn->session->id)
            ->call('sendReply', 'execute now')
            ->assertRedirect();

        $this->assertSame(1, Quiz::query()->count());
        $quiz = Quiz::query()->first();
        $this->assertNotEmpty($quiz->draft_definition['blocks'] ?? []);
        $this->assertSame('generated', $turn->session->fresh()->status);
    }
}

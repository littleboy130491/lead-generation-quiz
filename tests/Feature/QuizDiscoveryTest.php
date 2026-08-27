<?php

namespace Tests\Feature;

use App\Actions\Quizzes\RunQuizDiscovery;
use App\Ai\Discovery\LaravelQuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Livewire\QuizDiscoveryChat;
use App\Models\Quiz;
use App\Models\QuizDiscoverySession;
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
        $session = app(RunQuizDiscovery::class)->start(
            User::factory()->create()->id,
            '<b>I need a lead quiz</b> for my consulting business.',
        );

        $this->assertSame('interviewing', $session->status);
        $this->assertSame('I need a lead quiz for my consulting business.', $session->brief['business_context']);
        $this->assertSame(['user', 'assistant'], $session->messages()->pluck('role')->all());
        $this->assertStringContainsString('outcome', $session->messages()->latest('id')->value('content'));
    }

    public function test_the_discovery_page_renders_the_interview_chat(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/quiz-discovery')
            ->assertOk()
            ->assertSee('AI quiz interview')
            ->assertSee('What quiz do you want to create?');
    }

    public function test_execute_now_marks_the_interview_ready_without_storing_the_command_as_a_brief_field(): void
    {
        $user = User::factory()->create();
        $session = app(RunQuizDiscovery::class)->start($user->id, 'A consulting quiz for operations leaders.');
        $session = app(RunQuizDiscovery::class)->reply($session, 'execute now');

        $this->assertSame('ready', $session->status);
        $this->assertSame('A consulting quiz for operations leaders.', $session->brief['business_context']);
        $this->assertNotSame('execute now', $session->brief['objective'] ?? null);
        $this->assertStringContainsString('Generating the quiz now', $session->messages()->latest('id')->value('content'));
    }

    public function test_completing_the_guided_interview_marks_the_session_ready_to_generate(): void
    {
        $session = app(RunQuizDiscovery::class)->start(
            User::factory()->create()->id,
            'Consulting firm quiz',
        );
        $session = app(RunQuizDiscovery::class)->reply($session, 'Help owners find bottlenecks');
        $session = app(RunQuizDiscovery::class)->reply($session, 'Owners of 10-50 person firms');
        $session = app(RunQuizDiscovery::class)->reply($session, 'The next operational action');

        $this->assertSame('ready', $session->status);
        $this->assertSame('Consulting firm quiz', $session->brief['business_context']);
        $this->assertSame('Help owners find bottlenecks', $session->brief['objective']);
        $this->assertSame('Owners of 10-50 person firms', $session->brief['target_audience']);
        $this->assertSame('The next operational action', $session->brief['desired_insight']);
    }

    public function test_chat_execute_now_creates_a_validated_quiz_draft(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'A consulting quiz for operations leaders.')
            ->call('sendReply', 'execute now');

        $quiz = Quiz::query()->sole();
        $component->assertRedirect(EditQuiz::getUrl(['record' => $quiz]));
        $this->assertSame('generated', QuizDiscoverySession::query()->sole()->status);
        $this->assertNotEmpty($quiz->draft_definition['blocks']);
        $this->assertSame(1, $quiz->draft_definition['schema_version']);
        $this->assertSame('A consulting quiz for operations leaders.', QuizDiscoverySession::query()->sole()->brief['business_context']);
    }

    public function test_completing_the_chat_interview_creates_a_quiz_draft(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'Consulting firm quiz')
            ->call('sendReply', 'Help owners find bottlenecks')
            ->call('sendReply', 'Owners of 10-50 person firms')
            ->call('sendReply', 'The next operational action')
            ->assertRedirect();

        $this->assertSame('generated', QuizDiscoverySession::query()->sole()->status);
        $this->assertSame(1, Quiz::query()->count());
        $this->assertNotEmpty(Quiz::query()->sole()->draft_definition['blocks']);
    }

    public function test_create_quiz_now_applies_the_draft_to_the_current_quiz_on_edit(): void
    {
        $user = User::factory()->create();
        $quiz = Quiz::factory()->create([
            'draft_definition' => ['schema_version' => 1, 'blocks' => []],
        ]);

        Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class, ['quizId' => $quiz->id])
            ->call('startDiscovery', 'Refresh this operations quiz')
            ->call('executeNow')
            ->assertRedirect(EditQuiz::getUrl(['record' => $quiz]));

        $this->assertSame(1, Quiz::query()->count());
        $this->assertNotEmpty($quiz->fresh()->draft_definition['blocks']);
    }
}

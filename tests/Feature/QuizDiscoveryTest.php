<?php

namespace Tests\Feature;

use App\Actions\Quizzes\GenerateQuizDraft;
use App\Actions\Quizzes\RunQuizDiscovery;
use App\Actions\Quizzes\StopQuizDraftGeneration;
use App\Ai\Contracts\QuizDefinitionGenerator;
use App\Ai\Discovery\LaravelQuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Ai\GenerationException;
use App\Ai\HeuristicQuizDefinitionGenerator;
use App\Enums\QuizDiscoveryStatus;
use App\Jobs\GenerateQuizDraftJob;
use App\Livewire\QuizDiscoveryChat;
use App\Models\Quiz;
use App\Models\QuizDiscoverySession;
use App\Models\QuizDraftGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

        $this->assertSame(QuizDiscoveryStatus::Interviewing, $session->status);
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

    public function test_the_composer_sends_on_enter_and_keeps_shift_enter_for_new_lines(): void
    {
        $composer = Livewire::actingAs(User::factory()->create())
            ->test(QuizDiscoveryChat::class)
            ->html();

        $this->assertStringContainsString('x-on:keydown.enter=', $composer);
        $this->assertStringContainsString('$event.shiftKey', $composer);
        $this->assertStringContainsString('$event.isComposing', $composer);
        $this->assertStringNotContainsString('keydown.enter.meta', $composer);
    }

    public function test_execute_now_marks_the_interview_ready_without_storing_the_command_as_a_brief_field(): void
    {
        $interviewer = new class implements QuizDiscoveryInterviewer
        {
            public int $calls = 0;

            public function respond(array $brief, array $messages, string $systemPrompt): array
            {
                $this->calls++;

                return ['message' => 'What outcome should the quiz create?', 'brief' => [], 'action' => 'continue'];
            }
        };
        $this->swap(QuizDiscoveryInterviewer::class, $interviewer);
        $user = User::factory()->create();
        $session = app(RunQuizDiscovery::class)->start($user->id, 'A consulting quiz for operations leaders.');
        $session = app(RunQuizDiscovery::class)->reply($session, 'execute now');

        $this->assertSame(QuizDiscoveryStatus::Ready, $session->status);
        $this->assertSame(1, $interviewer->calls, 'The explicit command must bypass a redundant interviewer call.');
        $this->assertSame('A consulting quiz for operations leaders.', $session->brief['business_context']);
        $this->assertNotSame('execute now', $session->brief['objective'] ?? null);
        $this->assertStringContainsString('generat', strtolower($session->messages()->latest('id')->value('content')));
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

        $this->assertSame(QuizDiscoveryStatus::Ready, $session->status);
        $this->assertSame('Consulting firm quiz', $session->brief['business_context']);
        $this->assertSame('Help owners find bottlenecks', $session->brief['objective']);
        $this->assertSame('Owners of 10-50 person firms', $session->brief['target_audience']);
        $this->assertSame('The next operational action', $session->brief['desired_insight']);
        $this->assertSame(RunQuizDiscovery::READY_MESSAGE, $session->messages()->latest('id')->value('content'));
        $this->assertStringNotContainsString('generating', strtolower($session->messages()->latest('id')->value('content')));
    }

    public function test_a_complete_brief_becomes_ready_without_overriding_the_interviewers_follow_up(): void
    {
        $this->swap(QuizDiscoveryInterviewer::class, new class implements QuizDiscoveryInterviewer
        {
            public function respond(array $brief, array $messages, string $systemPrompt): array
            {
                return [
                    'message' => 'Does this feel right? If so, I will generate the quiz draft.',
                    'brief' => [
                        'business_context' => 'Coaching service for dating app users',
                        'objective' => 'Build confidence about preferences',
                        'target_audience' => 'Adults on dating apps',
                        'desired_insight' => 'A personalized archetype',
                    ],
                    'action' => 'continue',
                ];
            }
        });

        $session = app(RunQuizDiscovery::class)->start(User::factory()->create()->id, 'A dating coaching quiz');

        $this->assertSame(QuizDiscoveryStatus::Ready, $session->status);
        $this->assertSame('Does this feel right? If so, I will generate the quiz draft.', $session->messages()->latest('id')->value('content'));
    }

    public function test_a_ready_interview_waits_for_an_explicit_generation_request_and_accepts_more_context(): void
    {
        Bus::fake();
        $this->swap(QuizDiscoveryInterviewer::class, new class implements QuizDiscoveryInterviewer
        {
            public int $calls = 0;

            public function respond(array $brief, array $messages, string $systemPrompt): array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    return [
                        'message' => 'I have enough context. You can create the quiz now, or keep chatting to make it more specific.',
                        'brief' => [
                            'business_context' => 'Web design consultancy',
                            'objective' => 'Qualify leads',
                            'target_audience' => 'Established business owners',
                            'desired_insight' => 'Whether their website supports trust and growth',
                        ],
                        'action' => 'generate',
                    ];
                }

                return [
                    'message' => 'What budget objections should the quiz address?',
                    'brief' => ['tone' => 'Confident and educational'],
                    'action' => 'continue',
                ];
            }
        });
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'Help me plan a website lead-generation quiz');

        $session = QuizDiscoverySession::query()->sole();

        $this->assertSame(QuizDiscoveryStatus::Ready, $session->status);
        $this->assertSame(0, Quiz::query()->count());
        Bus::assertNotDispatched(GenerateQuizDraftJob::class);
        $component->assertSee('Create quiz now')->assertSee('keep chatting');

        $component->call('sendReply', 'Use a confident and educational tone.');

        $this->assertSame(QuizDiscoveryStatus::Ready, $session->fresh()->status);
        $this->assertSame('Confident and educational', $session->fresh()->brief['tone']);
        $this->assertSame('What budget objections should the quiz address?', $session->messages()->latest('id')->value('content'));
        $this->assertSame(0, Quiz::query()->count());
        Bus::assertNotDispatched(GenerateQuizDraftJob::class);

        $component->call('executeNow');

        $this->assertSame(QuizDiscoveryStatus::Generating, $session->fresh()->status);
        $this->assertSame(1, Quiz::query()->count());
        $this->assertSame(RunQuizDiscovery::GENERATION_REQUESTED_MESSAGE, $session->messages()->latest('id')->value('content'));
        Bus::assertDispatched(GenerateQuizDraftJob::class);
    }

    public function test_only_the_most_recent_turns_are_replayed_to_the_provider(): void
    {
        $seen = new class
        {
            public array $history = [];
        };

        $this->swap(QuizDiscoveryInterviewer::class, new class($seen) implements QuizDiscoveryInterviewer
        {
            public function __construct(private object $seen) {}

            public function respond(array $brief, array $messages, string $systemPrompt): array
            {
                $this->seen->history = $messages;

                return ['message' => 'And who is it for?', 'brief' => [], 'action' => 'continue'];
            }
        });

        $session = app(RunQuizDiscovery::class)->start(User::factory()->create()->id, 'Turn 1');
        foreach (range(2, 12) as $turn) {
            $session = app(RunQuizDiscovery::class)->reply($session, 'Turn '.$turn);
        }

        $this->assertCount(RunQuizDiscovery::HISTORY_TURNS, $seen->history);
        $this->assertSame('Turn 12', end($seen->history)['content']);
        $this->assertNotContains('Turn 1', array_column($seen->history, 'content'));
        $this->assertGreaterThan(RunQuizDiscovery::HISTORY_TURNS, $session->messages()->count());
    }

    public function test_a_concurrent_turn_for_the_same_session_is_dropped_rather_than_interleaved(): void
    {
        $session = app(RunQuizDiscovery::class)->start(User::factory()->create()->id, 'Consulting firm quiz');
        $before = $session->messages()->count();

        $lock = Cache::lock('quiz-discovery-session:'.$session->id, 300);
        $lock->get();

        $result = app(RunQuizDiscovery::class)->reply($session, 'Help owners find bottlenecks');
        $lock->release();

        $this->assertSame($before, $result->messages()->count());
        $this->assertSame($before, $session->fresh()->messages()->count());
    }

    public function test_chat_execute_now_queues_generation_and_returns_the_generating_state(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'A consulting quiz for operations leaders.')
            ->call('sendReply', 'execute now');

        $quiz = Quiz::query()->sole();
        $session = QuizDiscoverySession::query()->sole();

        $this->assertSame(QuizDiscoveryStatus::Generating, $session->status);
        $this->assertSame($quiz->id, $session->quiz_id);
        $this->assertEmpty($quiz->draft_definition['blocks']);
        $this->assertSame('A consulting quiz for operations leaders.', $session->brief['business_context']);
        $component->assertSee('Generating your quiz draft');
        Bus::assertDispatched(GenerateQuizDraftJob::class, fn (GenerateQuizDraftJob $job): bool => $job->sessionId === $session->id && $job->queue === 'ai');
    }

    public function test_generation_job_completes_the_chat_and_is_idempotent(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'Consulting firm quiz for operations leaders')
            ->call('sendReply', 'execute now');

        $session = QuizDiscoverySession::query()->sole();
        $job = new GenerateQuizDraftJob($session->id, (string) $session->generation_token);
        $job->handle(app(GenerateQuizDraft::class));
        $generationCount = QuizDraftGeneration::query()->count();
        $messageCount = $session->messages()->count();

        $job->handle(app(GenerateQuizDraft::class));
        $component->call('pollGeneration');

        $this->assertSame(QuizDiscoveryStatus::Generated, $session->fresh()->status);
        $this->assertSame(1, Quiz::query()->count());
        $this->assertNotEmpty(Quiz::query()->sole()->draft_definition['blocks']);
        $this->assertSame($generationCount, QuizDraftGeneration::query()->count());
        $this->assertSame($messageCount, $session->messages()->count());
        $component->assertSee('Your quiz draft is ready')->assertSee('Review and edit quiz');
    }

    public function test_generation_job_records_failure_and_the_chat_can_retry(): void
    {
        Bus::fake();
        $this->swap(QuizDefinitionGenerator::class, new class implements QuizDefinitionGenerator
        {
            public function generate(array $brief, array $providerChain, string $systemPrompt): array
            {
                throw new GenerationException('ai_generation_failed', 'All configured AI providers failed.', [
                    ['provider' => 'openrouter', 'model' => 'slow-model', 'message' => 'Timed out.'],
                ]);
            }
        });

        $component = Livewire::actingAs(User::factory()->create())
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'Consulting quiz for operations leaders')
            ->call('executeNow');
        $session = QuizDiscoverySession::query()->sole();

        (new GenerateQuizDraftJob($session->id, (string) $session->generation_token))->handle(app(GenerateQuizDraft::class));
        $component->call('pollGeneration');

        $this->assertSame(QuizDiscoveryStatus::Failed, $session->fresh()->status);
        $component->assertSee('could not be completed')->assertSee('Try again');

        $component->call('generateDraft');

        $this->assertSame(QuizDiscoveryStatus::Generating, $session->fresh()->status);
        Bus::assertDispatchedTimes(GenerateQuizDraftJob::class, 2);
    }

    public function test_the_session_owner_can_stop_queued_generation_and_retry(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'Consulting quiz for operations leaders')
            ->call('executeNow')
            ->assertSee('Stop generation');

        $session = QuizDiscoverySession::query()->sole();
        $stoppedExecutionToken = (string) $session->generation_token;

        $component->call('stopGeneration');

        $stoppedSession = $session->fresh();
        $this->assertSame(QuizDiscoveryStatus::Cancelled, $stoppedSession->status);
        $this->assertNotNull($stoppedSession->generation_finished_at);
        $this->assertSame('cancelled_by_admin', $stoppedSession->generation_error_code);
        $this->assertSame('Quiz draft generation was stopped.', $session->messages()->latest('id')->value('content'));
        $component->assertSee('Generation stopped')->assertSee('Try again')->assertDontSee('Stop generation');

        $component->call('generateDraft');

        $this->assertSame(QuizDiscoveryStatus::Generating, $session->fresh()->status);
        $this->assertNull($session->fresh()->generation_error_code);
        $this->assertNotSame($stoppedExecutionToken, (string) $session->fresh()->generation_token);
        Bus::assertDispatchedTimes(GenerateQuizDraftJob::class, 2);

        (new GenerateQuizDraftJob($session->id, $stoppedExecutionToken))->handle(app(GenerateQuizDraft::class));

        $this->assertSame(QuizDiscoveryStatus::Generating, $session->fresh()->status);
        $this->assertNull($session->fresh()->generation_started_at);
        $this->assertSame(0, QuizDraftGeneration::query()->count());
    }

    public function test_an_administrator_cannot_stop_another_administrators_generation(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $otherAdministrator = User::factory()->create();

        Livewire::actingAs($owner)
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'Consulting quiz for operations leaders')
            ->call('executeNow');

        $session = QuizDiscoverySession::query()->sole();

        Livewire::actingAs($otherAdministrator)
            ->test(QuizDiscoveryChat::class)
            ->set('sessionId', $session->id)
            ->call('stopGeneration');

        $this->assertSame(QuizDiscoveryStatus::Generating, $session->fresh()->status);
        $this->assertNotSame('Quiz draft generation was stopped.', $session->messages()->latest('id')->value('content'));
    }

    public function test_a_stopped_in_flight_generation_cannot_persist_its_late_result(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $originalDefinition = ['schema_version' => 1, 'blocks' => []];

        $this->swap(QuizDefinitionGenerator::class, new class implements QuizDefinitionGenerator
        {
            public function generate(array $brief, array $providerChain, string $systemPrompt): array
            {
                $session = QuizDiscoverySession::query()->sole();
                app(StopQuizDraftGeneration::class)->handle($session->id, $session->user_id);

                return app(HeuristicQuizDefinitionGenerator::class)->generate($brief);
            }
        });

        Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->call('startDiscovery', 'Consulting quiz for operations leaders')
            ->call('executeNow');

        $session = QuizDiscoverySession::query()->sole();
        $quiz = $session->quiz;
        $quiz->update(['draft_definition' => $originalDefinition]);

        (new GenerateQuizDraftJob($session->id, (string) $session->generation_token))->handle(app(GenerateQuizDraft::class));

        $audit = QuizDraftGeneration::query()->sole();

        $this->assertSame(QuizDiscoveryStatus::Cancelled, $session->fresh()->status);
        $this->assertSame($originalDefinition, $quiz->fresh()->draft_definition);
        $this->assertSame('cancelled', $audit->status);
        $this->assertNotNull($audit->cancelled_at);
        $this->assertNull($audit->completed_at);
        $this->assertNull($audit->failed_at);
        $this->assertSame('Quiz draft generation was stopped.', $session->messages()->latest('id')->value('content'));
    }

    public function test_create_quiz_now_queues_a_draft_for_the_current_quiz_on_edit(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $quiz = Quiz::factory()->create([
            'draft_definition' => ['schema_version' => 1, 'blocks' => []],
        ]);

        Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class, ['quizId' => $quiz->id])
            ->call('startDiscovery', 'Refresh this operations quiz')
            ->call('executeNow');

        $this->assertSame(1, Quiz::query()->count());
        $this->assertSame($quiz->id, QuizDiscoverySession::query()->sole()->quiz_id);
        $this->assertSame(QuizDiscoveryStatus::Generating, QuizDiscoverySession::query()->sole()->status);
        Bus::assertDispatched(GenerateQuizDraftJob::class);
    }
}

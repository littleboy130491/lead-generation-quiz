<?php

namespace Tests\Feature;

use App\Actions\Quizzes\GenerateQuizDraft;
use App\Actions\Quizzes\PublishQuizRevision;
use App\Actions\Quizzes\RunQuizDiscovery;
use App\Actions\Quizzes\StopQuizDraftGeneration;
use App\Ai\Contracts\QuizDefinitionGenerator;
use App\Ai\Discovery\LaravelQuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Ai\GenerationException;
use App\Ai\HeuristicQuizDefinitionGenerator;
use App\Enums\QuizDiscoveryMode;
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

    public function test_assistant_messages_render_safe_markdown_while_user_messages_remain_plain_text(): void
    {
        $user = User::factory()->create();
        $session = QuizDiscoverySession::query()->create([
            'user_id' => $user->id,
            'status' => QuizDiscoveryStatus::Interviewing,
            'brief' => [],
            'system_prompt_snapshot' => 'Safe discovery prompt.',
        ]);
        $session->messages()->create([
            'role' => 'user',
            'content' => '**Keep this plain** <script>alert("user")</script>',
        ]);
        $session->messages()->create([
            'role' => 'assistant',
            'content' => "## Recommendation\n\n- Keep the strongest question\n- Shorten the introduction\n\n[Unsafe](javascript:alert('assistant'))\n\n<script>alert('assistant')</script>",
        ]);

        $html = Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->html();

        $this->assertStringContainsString('<h2>Recommendation</h2>', $html);
        $this->assertStringContainsString('<li>Keep the strongest question</li>', $html);
        $this->assertStringContainsString('**Keep this plain**', $html);
        $this->assertStringNotContainsString('<strong>Keep this plain</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_user_message_text_is_isolated_from_template_whitespace(): void
    {
        $user = User::factory()->create();
        $session = QuizDiscoverySession::query()->create([
            'user_id' => $user->id,
            'status' => QuizDiscoveryStatus::Interviewing,
            'brief' => [],
            'system_prompt_snapshot' => 'Safe discovery prompt.',
        ]);
        $session->messages()->create([
            'role' => 'user',
            'content' => "sure\nsecond line",
        ]);

        $html = Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class)
            ->html();

        $this->assertStringContainsString('<span class="quiz-chat__plain-text">sure'."\n".'second line</span>', $html);
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

    public function test_an_unspecified_interviewer_question_count_is_left_for_the_generation_agent(): void
    {
        $this->swap(QuizDiscoveryInterviewer::class, new class implements QuizDiscoveryInterviewer
        {
            public function respond(array $brief, array $messages, string $systemPrompt): array
            {
                return [
                    'message' => 'The brief is ready, and the generation agent will choose the ideal quiz length.',
                    'brief' => [
                        'business_context' => 'Nutrition coaching for busy founders',
                        'objective' => 'Identify the most useful dietary next step',
                        'target_audience' => 'Busy founders',
                        'desired_insight' => 'Their primary dietary obstacle',
                        'question_count' => 0,
                    ],
                    'action' => 'generate',
                ];
            }
        });

        $session = app(RunQuizDiscovery::class)->start(User::factory()->create()->id, 'Create a nutrition coaching quiz');

        $this->assertSame(QuizDiscoveryStatus::Ready, $session->status);
        $this->assertArrayNotHasKey('question_count', $session->brief);
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
        $html = $component->html();
        $headerEnd = strpos($html, '</header>');
        $assistantMessage = strrpos($html, '<article class="quiz-chat__message quiz-chat__message--assistant">');
        $conversationAction = strpos($html, '<div class="quiz-chat__conversation-action">');
        $this->assertIsInt($headerEnd);
        $this->assertIsInt($assistantMessage);
        $this->assertIsInt($conversationAction);
        $this->assertGreaterThan($headerEnd, $conversationAction);
        $this->assertGreaterThan($assistantMessage, $conversationAction);
        $this->assertStringNotContainsString('wire:click="executeNow"', substr($html, 0, $headerEnd));

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

    public function test_edit_interview_sends_an_immutable_existing_quiz_snapshot_to_the_agent(): void
    {
        Bus::fake();
        $seen = new class
        {
            public array $messages = [];
        };
        $this->swap(QuizDiscoveryInterviewer::class, new class($seen) implements QuizDiscoveryInterviewer
        {
            public function __construct(private object $seen) {}

            public function respond(array $brief, array $messages, string $systemPrompt): array
            {
                $this->seen->messages = $messages;

                return [
                    'message' => 'I recommend improving the diagnostic flow. You can update the quiz now or keep refining it.',
                    'brief' => [
                        'business_context' => 'Existing operations assessment',
                        'objective' => 'Improve diagnostic usefulness',
                        'target_audience' => 'Operations leaders',
                        'desired_insight' => 'A prioritized operational next step',
                    ],
                    'action' => 'generate',
                ];
            }
        });
        $quiz = Quiz::factory()->create([
            'name' => 'Operations assessment',
            'description' => 'Find the next operational priority.',
            'draft_definition' => [
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'existing-readiness',
                    'type' => 'question',
                    'question_type' => 'yes_no',
                    'label' => 'Is your delivery process documented?',
                ]],
            ],
        ]);

        $component = Livewire::actingAs(User::factory()->create())
            ->test(QuizDiscoveryChat::class, ['quizId' => $quiz->id, 'mode' => 'edit'])
            ->assertSee('How should this quiz improve?')
            ->call('startDiscovery', 'Make the recommendations more actionable.');

        $session = QuizDiscoverySession::query()->sole();
        $context = json_encode($seen->messages[0] ?? [], JSON_THROW_ON_ERROR);

        $this->assertSame(QuizDiscoveryMode::Edit, $session->mode);
        $this->assertSame('existing-readiness', $session->source_quiz_snapshot['draft_definition']['blocks'][0]['id']);
        $this->assertArrayNotHasKey('settings', $session->source_quiz_snapshot);
        $this->assertStringContainsString('existing-readiness', $context);
        $this->assertStringContainsString('Operations assessment', $context);
        $component->assertSee('Update quiz')->assertDontSee('Create quiz now');
        $this->assertSame('existing-readiness', $quiz->fresh()->draft_definition['blocks'][0]['id']);
        Bus::assertNotDispatched(GenerateQuizDraftJob::class);

        $this->expectException(\LogicException::class);
        $session->update(['source_quiz_snapshot' => ['name' => 'Changed after the interview']]);
    }

    public function test_edit_interview_does_not_offer_or_apply_an_update_before_the_agent_is_ready(): void
    {
        Bus::fake();
        $interviewer = new class implements QuizDiscoveryInterviewer
        {
            public int $calls = 0;

            public function respond(array $brief, array $messages, string $systemPrompt): array
            {
                $this->calls++;

                return [
                    'message' => $this->calls === 1
                        ? 'What should the updated result help respondents decide?'
                        : 'I recommend a shorter diagnostic flow. You can update the quiz or keep refining it.',
                    'brief' => [
                        'business_context' => 'Existing assessment',
                        'objective' => 'Improve the result',
                        'target_audience' => 'Business owners',
                        'desired_insight' => 'A clear next step',
                    ],
                    'action' => $this->calls === 1 ? 'continue' : 'generate',
                ];
            }
        };
        $this->swap(QuizDiscoveryInterviewer::class, $interviewer);
        $quiz = Quiz::factory()->create();

        $component = Livewire::actingAs(User::factory()->create())
            ->test(QuizDiscoveryChat::class, ['quizId' => $quiz->id, 'mode' => 'edit'])
            ->call('startDiscovery', 'Improve this quiz')
            ->assertDontSee('Update quiz');

        $component->call('executeNow');
        $this->assertSame(QuizDiscoveryStatus::Interviewing, QuizDiscoverySession::query()->sole()->status);
        Bus::assertNotDispatched(GenerateQuizDraftJob::class);

        $component->call('sendReply', 'It should recommend one prioritized action.')
            ->assertSee('Update quiz');
        $this->assertSame(QuizDiscoveryStatus::Ready, QuizDiscoverySession::query()->sole()->status);
    }

    public function test_explicit_ai_update_replaces_the_complete_draft_and_preserves_the_published_revision(): void
    {
        Bus::fake();
        $publishedDefinition = app(HeuristicQuizDefinitionGenerator::class)->generate([
            'business_context' => 'Published baseline',
            'question_count' => 1,
        ]);
        $quiz = Quiz::factory()->create([
            'name' => 'Immutable identity',
            'slug' => 'immutable-identity',
            'settings' => ['collect_name' => true],
            'draft_definition' => $publishedDefinition,
        ]);
        $revision = app(PublishQuizRevision::class)->handle($quiz);
        $existingDraft = [
            'schema_version' => 1,
            'result' => ['mode' => 'ai'],
            'blocks' => [[
                'id' => 'old-draft-question',
                'type' => 'question',
                'question_type' => 'yes_no',
                'label' => 'Old editable question?',
            ]],
        ];
        $quiz->update(['draft_definition' => $existingDraft]);

        $this->swap(QuizDiscoveryInterviewer::class, new class implements QuizDiscoveryInterviewer
        {
            public function respond(array $brief, array $messages, string $systemPrompt): array
            {
                return [
                    'message' => 'I recommend replacing the old question with a focused readiness sequence.',
                    'brief' => [
                        'business_context' => 'Improve the existing assessment',
                        'objective' => 'Make recommendations actionable',
                        'target_audience' => 'Operations leaders',
                        'desired_insight' => 'Their next operational priority',
                    ],
                    'action' => 'generate',
                ];
            }
        });
        $seen = new class
        {
            public array $brief = [];
        };
        $this->swap(QuizDefinitionGenerator::class, new class($seen) implements QuizDefinitionGenerator
        {
            public function __construct(private object $seen) {}

            public function generate(array $brief, array $providerChain, string $systemPrompt): array
            {
                $this->seen->brief = $brief;

                return [
                    'schema_version' => 1,
                    'result' => ['mode' => 'ai'],
                    'blocks' => [[
                        'id' => 'replacement-question',
                        'type' => 'question',
                        'question_type' => 'yes_no',
                        'label' => 'Is the highest-priority process documented?',
                    ]],
                ];
            }
        });
        $component = Livewire::actingAs(User::factory()->create())
            ->test(QuizDiscoveryChat::class, ['quizId' => $quiz->id, 'mode' => 'edit'])
            ->call('startDiscovery', 'Make this quiz more actionable.')
            ->assertSee('Update quiz');

        $component->call('executeNow');
        $session = QuizDiscoverySession::query()->sole();
        $this->assertSame(QuizDiscoveryStatus::Generating, $session->status);
        $this->assertSame('old-draft-question', $quiz->fresh()->draft_definition['blocks'][0]['id']);
        Bus::assertDispatched(GenerateQuizDraftJob::class);

        (new GenerateQuizDraftJob($session->id, (string) $session->generation_token))->handle(app(GenerateQuizDraft::class));

        $updated = $quiz->fresh();
        $this->assertSame('replacement-question', $updated->draft_definition['blocks'][0]['id']);
        $this->assertSame('old-draft-question', $seen->brief['existing_quiz']['draft_definition']['blocks'][0]['id']);
        $this->assertSame('Immutable identity', $updated->name);
        $this->assertSame('immutable-identity', $updated->slug);
        $this->assertSame(['collect_name' => true], $updated->settings);
        $this->assertSame($revision->id, $updated->active_revision_id);
        $this->assertSame($publishedDefinition, $revision->fresh()->definition);
        $this->assertSame(QuizDiscoveryStatus::Generated, $session->fresh()->status);
        $component->call('pollGeneration')->assertSee('Quiz draft updated');
    }

    public function test_a_completed_edit_can_continue_with_the_newly_updated_draft_as_fresh_context(): void
    {
        $seen = new class
        {
            public array $messages = [];
        };
        $this->swap(QuizDiscoveryInterviewer::class, new class($seen) implements QuizDiscoveryInterviewer
        {
            public function __construct(private object $seen) {}

            public function respond(array $brief, array $messages, string $systemPrompt): array
            {
                $this->seen->messages = $messages;

                return [
                    'message' => 'I recommend shortening the updated flow to three focused questions. You can update the quiz when ready.',
                    'brief' => ['objective' => 'Make the updated flow more concise'],
                    'action' => 'generate',
                ];
            }
        });
        $user = User::factory()->create();
        $quiz = Quiz::factory()->create([
            'draft_definition' => [
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'newly-updated-question',
                    'type' => 'question',
                    'question_type' => 'yes_no',
                    'label' => 'Is the updated process documented?',
                ]],
            ],
        ]);
        $completed = QuizDiscoverySession::query()->create([
            'user_id' => $user->id,
            'quiz_id' => $quiz->id,
            'mode' => QuizDiscoveryMode::Edit,
            'status' => QuizDiscoveryStatus::Generated,
            'brief' => ['business_context' => 'Operations assessment'],
            'source_quiz_snapshot' => [
                'name' => $quiz->name,
                'description' => $quiz->description,
                'draft_definition' => ['schema_version' => 1, 'blocks' => [['id' => 'pre-update-question']]],
            ],
            'system_prompt_snapshot' => 'Safe edit prompt.',
            'generation_finished_at' => now(),
        ]);
        $completed->messages()->create([
            'role' => 'assistant',
            'content' => 'Your quiz draft was updated. Review the complete replacement before publishing.',
            'brief_snapshot' => $completed->brief,
        ]);

        $component = Livewire::actingAs($user)
            ->test(QuizDiscoveryChat::class, ['quizId' => $quiz->id, 'mode' => 'edit'])
            ->assertSee('Keep refining')
            ->assertSee('Your quiz draft was updated')
            ->call('sendReply', 'Keep the update, but make the flow shorter.');

        $refinement = QuizDiscoverySession::query()->whereKeyNot($completed->id)->sole();
        $context = json_encode($seen->messages, JSON_THROW_ON_ERROR);

        $this->assertSame($completed->id, $refinement->continued_from_session_id);
        $this->assertSame(QuizDiscoveryStatus::Generated, $completed->fresh()->status);
        $this->assertSame(QuizDiscoveryStatus::Ready, $refinement->status);
        $this->assertSame('Operations assessment', $refinement->brief['business_context']);
        $this->assertSame('newly-updated-question', $refinement->source_quiz_snapshot['draft_definition']['blocks'][0]['id']);
        $this->assertStringContainsString('newly-updated-question', $context);
        $this->assertStringContainsString('Your quiz draft was updated', $context);
        $this->assertStringContainsString('Keep the update, but make the flow shorter.', $context);
        $component
            ->assertSee('Your quiz draft was updated')
            ->assertSee('Keep the update, but make the flow shorter.')
            ->assertSee('Update quiz');

        $quiz->update([
            'draft_definition' => [
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'twice-updated-question',
                    'type' => 'question',
                    'question_type' => 'yes_no',
                    'label' => 'Is the twice-refined process documented?',
                ]],
            ],
        ]);
        $refinement->update([
            'status' => QuizDiscoveryStatus::Generated,
            'generation_finished_at' => now(),
        ]);
        $refinement->messages()->create([
            'role' => 'assistant',
            'content' => 'Your quiz draft was updated again.',
            'brief_snapshot' => $refinement->brief,
        ]);

        $component
            ->call('pollGeneration')
            ->assertSee('Keep refining')
            ->call('sendReply', 'One more pass: make the wording friendlier.');

        $secondRefinement = QuizDiscoverySession::query()
            ->where('continued_from_session_id', $refinement->id)
            ->sole();
        $this->assertSame('twice-updated-question', $secondRefinement->source_quiz_snapshot['draft_definition']['blocks'][0]['id']);
        $this->assertSame(QuizDiscoveryStatus::Generated, $refinement->fresh()->status);
        $component
            ->assertSee('Your quiz draft was updated again.')
            ->assertSee('One more pass: make the wording friendlier.')
            ->assertSee('Update quiz');
    }

    public function test_another_administrator_cannot_continue_an_owners_completed_edit(): void
    {
        $owner = User::factory()->create();
        $quiz = Quiz::factory()->create();
        $completed = QuizDiscoverySession::query()->create([
            'user_id' => $owner->id,
            'quiz_id' => $quiz->id,
            'mode' => QuizDiscoveryMode::Edit,
            'status' => QuizDiscoveryStatus::Generated,
            'brief' => ['business_context' => 'Private assessment'],
            'source_quiz_snapshot' => [
                'name' => $quiz->name,
                'description' => $quiz->description,
                'draft_definition' => $quiz->draft_definition,
            ],
            'system_prompt_snapshot' => 'Safe edit prompt.',
            'generation_finished_at' => now(),
        ]);
        $completed->messages()->create([
            'role' => 'assistant',
            'content' => 'Private completion message.',
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(QuizDiscoveryChat::class, ['quizId' => $quiz->id, 'mode' => 'edit'])
            ->assertDontSee('Private completion message.')
            ->call('sendReply', 'Change the private quiz.');

        $this->assertSame(1, QuizDiscoverySession::query()->count());
        $this->assertSame(1, $completed->messages()->count());
    }
}

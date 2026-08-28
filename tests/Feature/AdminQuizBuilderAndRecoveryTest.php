<?php

namespace Tests\Feature;

use App\Actions\Deliveries\RequestReportDelivery;
use App\Actions\Quizzes\PublishQuizRevision;
use App\Ai\Contracts\QuizAnalysisGenerator;
use App\Ai\Data\ReportSchema;
use App\Domain\Quiz\Pagination\QuizPageCompiler;
use App\Domain\Quiz\Validation\QuizDefinitionValidator;
use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTrigger;
use App\Enums\DeliveryStatus;
use App\Filament\Resources\Quizzes\Pages\CreateQuiz;
use App\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Filament\Resources\Quizzes\Schemas\QuizForm;
use App\Jobs\GenerateAnalysisJob;
use App\Jobs\SendReportDeliveryJob;
use App\Models\Analysis;
use App\Models\Quiz;
use App\Models\QuizRevision;
use App\Models\ReportDelivery;
use App\Models\Submission;
use App\Models\User;
use App\Settings\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminQuizBuilderAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_administrator_can_open_the_quiz_builder(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/quizzes/create')
            ->assertOk()
            ->assertSee('AI quiz interview')
            ->assertDontSee('Generate AI draft')
            ->assertSee('Settings')
            ->assertSee('Result')
            ->assertSee('Thank you')
            ->assertSee('Opening page')
            ->assertSee('Access and lead settings')
            ->assertSee('fi-width-full', false)
            ->assertDontSee('fi-width-7xl', false);
    }

    public function test_authenticated_administrator_can_open_the_existing_quiz_builder(): void
    {
        $user = User::factory()->create();
        $quiz = Quiz::factory()->create(['draft_definition' => [
            'schema_version' => 1,
            'blocks' => [[
                'id' => 'business-stage',
                'type' => 'question',
                'question_type' => 'yes_no',
                'label' => 'Is the business ready?',
            ]],
        ]]);

        $this->actingAs($user)
            ->get("/admin/quizzes/{$quiz->id}/edit")
            ->assertOk()
            ->assertSee('Edit with AI')
            ->assertDontSee('AI quiz interview')
            ->assertDontSee('Generate AI draft')
            ->assertSee('Settings')
            ->assertSee('Quiz')
            ->assertSee('Result')
            ->assertSee('Thank you')
            ->assertSee('Opening page')
            ->assertSee('fi-width-full', false)
            ->assertDontSee('fi-width-7xl', false);
    }

    public function test_create_and_edit_quiz_headers_offer_distinct_ai_workflows(): void
    {
        $this->configureQuizAiProvider();
        $quiz = Quiz::factory()->create();

        $create = Livewire::actingAs(User::factory()->create())
            ->test(CreateQuiz::class)
            ->assertSee('AI quiz interview')
            ->assertDontSee('Generate AI draft')
            ->mountAction('quizDiscovery');

        $this->assertNotNull($create->instance()->getMountedAction());
        $this->assertStringContainsString('create the quiz now', (string) $create->instance()->getMountedAction()->getModalDescription());

        $edit = Livewire::actingAs(User::factory()->create())
            ->test(EditQuiz::class, ['record' => $quiz->id])
            ->assertSee('Edit with AI')
            ->assertDontSee('AI quiz interview')
            ->assertDontSee('Generate AI draft')
            ->mountAction('editWithAi');

        $this->assertNotNull($edit->instance()->getMountedAction());
        $this->assertStringContainsString('existing quiz', (string) $edit->instance()->getMountedAction()->getModalDescription());
        $this->assertNotContains('quizDiscovery', collect($edit->instance()->getCachedHeaderActions())->map->getName()->all());
    }

    public function test_quiz_interview_action_is_disabled_without_configured_quiz_ai_credentials(): void
    {
        config(['ai.providers.openai.key' => null]);
        app(ApplicationSettings::class)->put('ai.quiz', [['provider' => 'openai', 'model' => 'gpt-5']]);

        $create = Livewire::actingAs(User::factory()->create())->test(CreateQuiz::class);
        $action = $create->instance()->getAction('quizDiscovery');

        $this->assertTrue($action->isDisabled());
        $this->assertStringContainsString('Operational settings', (string) $action->getTooltip());

        $quiz = Quiz::factory()->create();
        $disabledEdit = Livewire::actingAs(User::factory()->create())->test(EditQuiz::class, ['record' => $quiz->id]);
        $this->assertTrue($disabledEdit->instance()->getAction('editWithAi')->isDisabled());

        $this->configureQuizAiProvider();

        $enabled = Livewire::actingAs(User::factory()->create())->test(CreateQuiz::class);
        $this->assertFalse($enabled->instance()->getAction('quizDiscovery')->isDisabled());

        $edit = Livewire::actingAs(User::factory()->create())->test(EditQuiz::class, ['record' => $quiz->id]);
        $this->assertFalse($edit->instance()->getAction('editWithAi')->isDisabled());
    }

    private function configureQuizAiProvider(): void
    {
        config(['ai.providers.openai.key' => 'sk-test']);
        app(ApplicationSettings::class)->put('ai.quiz', [['provider' => 'openai', 'model' => 'gpt-5']]);
    }

    public function test_authenticated_administrator_can_open_preview_and_revision_history_surfaces(): void
    {
        $user = User::factory()->create();
        $quiz = Quiz::factory()->create(['draft_definition' => ['schema_version' => 1, 'blocks' => [['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready?']]]]);
        $revision = app(PublishQuizRevision::class)->handle($quiz, $user->id);

        $this->actingAs($user)->get(route('admin.quizzes.preview', $quiz))->assertOk()->assertSee('draft preview')->assertSee('Ready?');
        $this->actingAs($user)->get(route('admin.quizzes.preview', ['quiz' => $quiz, 'revision' => $revision->id]))->assertOk()->assertSee('published revision 1');
        $this->actingAs($user)->get(route('admin.quizzes.history', $quiz))->assertOk()->assertSee('Version 1');
    }

    public function test_edit_quiz_header_includes_preview_action_to_interactive_draft_url(): void
    {
        $quiz = Quiz::factory()->create(['slug' => 'previewable-draft']);

        $page = Livewire::actingAs(User::factory()->create())
            ->test(EditQuiz::class, ['record' => $quiz->id]);

        $action = collect($page->instance()->getCachedHeaderActions())->first(fn ($action) => $action->getName() === 'preview');
        $this->assertNotNull($action);
        $this->assertSame(route('quizzes.draft-preview.show', $quiz), $action->getUrl());
    }

    public function test_published_quiz_keeps_serving_its_revision_while_admin_draft_preview_honors_new_page_breaks(): void
    {
        $user = User::factory()->create();
        $quiz = Quiz::factory()->create([
            'slug' => 'published-with-new-draft-pages',
            'draft_definition' => [
                'schema_version' => 1,
                'blocks' => [
                    ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Published first question', 'required' => true],
                    ['id' => 'q2', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Published second question', 'required' => true],
                ],
            ],
        ]);
        app(PublishQuizRevision::class)->handle($quiz, $user->id);
        $quiz->update(['draft_definition' => [
            'schema_version' => 1,
            'blocks' => [
                ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Draft first question', 'required' => true],
                ['id' => 'page_break_1', 'type' => 'page_break'],
                ['id' => 'q2', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Draft second question', 'required' => true],
            ],
        ]]);

        $this->get(route('quizzes.show', $quiz))
            ->assertOk()
            ->assertSee('Page 1 of 1')
            ->assertSee('Published first question')
            ->assertSee('Published second question')
            ->assertDontSee('Draft first question');
        $this->assertDatabaseCount('submissions', 1);

        $this->actingAs($user)
            ->get(route('quizzes.draft-preview.show', $quiz))
            ->assertOk()
            ->assertSee('Draft preview')
            ->assertSee('Page 1 of 2')
            ->assertSee('Draft first question')
            ->assertDontSee('Draft second question');
        $this->assertDatabaseCount('submissions', 1);

        $this->post(route('quizzes.draft-preview.save-page', [$quiz, 0]), [
            'answers' => ['q1' => 'yes'],
            'direction' => 'next',
        ])->assertRedirect(route('quizzes.draft-preview.show', $quiz));

        $this->get(route('quizzes.draft-preview.show', $quiz))
            ->assertOk()
            ->assertSee('Page 2 of 2')
            ->assertSee('Draft second question')
            ->assertDontSee('Draft first question');
        $this->assertDatabaseCount('submissions', 1);
    }

    public function test_guests_cannot_open_draft_quizzes_but_authenticated_users_can_live_preview_them(): void
    {
        $quiz = Quiz::factory()->create([
            'slug' => 'draft-live-preview',
            'status' => 'draft',
            'draft_definition' => [
                'schema_version' => 1,
                'blocks' => [[
                    'id' => 'q1',
                    'type' => 'question',
                    'question_type' => 'yes_no',
                    'label' => 'Draft question ready?',
                    'required' => true,
                ]],
            ],
        ]);

        $this->get(route('quizzes.show', $quiz))->assertNotFound();
        $this->assertDatabaseCount('submissions', 0);

        $this->actingAs(User::factory()->create())
            ->get(route('quizzes.show', $quiz))
            ->assertOk()
            ->assertSee('Draft preview')
            ->assertSee('Draft question ready?')
            ->assertSee('not published yet');

        $this->assertDatabaseCount('submissions', 0);

        $this->actingAs(User::factory()->create())
            ->post(route('quizzes.draft-preview.save-page', [$quiz, 0]), [
                'answers' => ['q1' => 'yes'],
                'direction' => 'next',
            ])
            ->assertRedirect(route('quizzes.draft-preview.complete', $quiz));

        $this->actingAs(User::factory()->create())
            ->get(route('quizzes.draft-preview.complete', $quiz))
            ->assertOk()
            ->assertSee('Draft preview finished');

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_admin_builder_payload_creates_a_publishable_multi_page_public_quiz_without_storing_a_raw_password(): void
    {
        $payload = [
            'opening' => [
                'html' => '<p>Welcome to the assessment</p>',
                'start_button_label' => 'Begin now',
                'hide_start_button' => false,
            ],
            'result' => ['mode' => 'score'],
            'thank_you' => ['enabled' => true, 'html' => '<h1>Custom thanks</h1>'],
            'score_results' => [
                ['id' => 'low', 'title' => 'Needs work', 'min_score' => 0, 'max_score' => 2, 'html' => '<p>Low</p>'],
                ['id' => 'high', 'title' => 'Ready', 'min_score' => 3, 'max_score' => 10, 'html' => '<p>High</p>'],
            ],
            'blocks' => [
                [
                    'type' => 'question',
                    'id' => 'q-readiness',
                    'question_type' => 'single_choice',
                    'label' => 'How ready are you?',
                    'help' => 'Choose one answer.',
                    'required' => true,
                    'image_url' => 'https://cdn.example.test/ready.png',
                    'icon' => '✅',
                    'options' => [['id' => 'ready', 'value' => 'ready', 'label' => 'Ready', 'score' => 5]],
                    'visibility' => null,
                ],
                ['type' => 'page_break', 'id' => 'page-two'],
                [
                    'type' => 'content',
                    'id' => 'intro-two',
                    'markdown' => 'Second page',
                    'continue_label' => 'Continue',
                    'visibility' => null,
                ],
                [
                    'type' => 'question',
                    'id' => 'q-detail',
                    'question_type' => 'long_text',
                    'label' => 'Tell us more',
                    'help' => null,
                    'required' => false,
                    'options' => [],
                    'visibility' => ['question_id' => 'q-readiness', 'operator' => 'equals', 'value' => 'ready'],
                ],
            ],
        ];

        $quiz = Quiz::create([
            'name' => 'Readiness assessment',
            'slug' => 'readiness-assessment',
            'status' => 'draft',
            'password_hash' => QuizForm::passwordForStorage('secret password'),
            'settings' => ['collect_name' => true],
            'draft_definition' => QuizForm::toDefinition($payload),
        ]);

        $revision = app(PublishQuizRevision::class)->handle($quiz);

        $this->assertSame(2, count(app(QuizPageCompiler::class)->compile($revision->definition)));
        $this->assertSame('Begin now', $revision->definition['opening']['start_button_label']);
        $this->assertSame('score', $revision->definition['result']['mode']);
        $this->assertTrue($revision->definition['thank_you']['enabled']);
        $this->assertSame('<h1>Custom thanks</h1>', $revision->definition['thank_you']['html']);
        $this->assertSame(5, $revision->definition['blocks'][0]['options'][0]['score']);
        $this->assertSame('https://cdn.example.test/ready.png', $revision->definition['blocks'][0]['image_url']);
        $this->assertSame('✅', $revision->definition['blocks'][0]['icon']);
        $this->assertCount(2, $revision->definition['score_results']);
        $this->assertTrue(Hash::check('secret password', $quiz->password_hash));
        $this->assertNotSame('secret password', $quiz->password_hash);
        $this->assertArrayNotHasKey('password_hash', QuizForm::toFormState($quiz));
        $this->assertSame('<p>Welcome to the assessment</p>', QuizForm::toFormState($quiz)['opening']['html']);
        $this->post('/readiness-assessment/unlock', ['password' => 'secret password'])->assertRedirect('/readiness-assessment');
        $this->get('/readiness-assessment')->assertOk()->assertSee('Welcome to the assessment')->assertSee('Begin now')->assertDontSee('How ready are you?');
    }

    public function test_quiz_persistence_serializes_virtual_form_sections_only_into_the_draft_definition(): void
    {
        $quiz = Quiz::factory()->create([
            'name' => 'Abs assessment',
            'slug' => 'abs-assessment',
            'draft_definition' => [
                'schema_version' => 1,
                'blocks' => [
                    ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready to begin?'],
                    ['id' => 'q2', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'What is your goal?'],
                ],
            ],
        ]);

        $persistenceData = QuizForm::toPersistenceData([
            'name' => 'Abs assessment',
            'slug' => 'abs-assessment',
            'status' => 'draft',
            'settings' => ['collect_name' => false],
            'opening' => [
                'html' => '<p>Welcome</p>',
                'start_button_label' => 'Begin',
                'hide_start_button' => false,
            ],
            'result' => ['mode' => 'ai', 'system_prompt' => 'Create a practical plan.'],
            'score_results' => [],
            'thank_you' => ['enabled' => true, 'html' => '<p>Thank you</p>'],
            'builder_blocks' => [
                ['type' => 'question', 'data' => ['id' => 'q1', 'question_type' => 'yes_no', 'label' => 'Ready to begin?']],
                ['type' => 'page_break', 'data' => ['id' => 'manual-break']],
                ['type' => 'question', 'data' => ['id' => 'q2', 'question_type' => 'short_text', 'label' => 'What is your goal?']],
            ],
        ]);

        $this->assertSame([], array_intersect(
            ['opening', 'result', 'score_results', 'thank_you', 'builder_blocks', 'password'],
            array_keys($persistenceData),
        ));

        $quiz->update($persistenceData);

        $definition = $quiz->fresh()->draft_definition;

        $this->assertSame('<p>Welcome</p>', $definition['opening']['html']);
        $this->assertSame('Create a practical plan.', $definition['result']['system_prompt']);
        $this->assertSame('<p>Thank you</p>', $definition['thank_you']['html']);
        $this->assertSame('manual-break', $definition['blocks'][1]['id']);
    }

    public function test_page_break_ids_are_generated_internally_and_remain_unique(): void
    {
        $definition = QuizForm::toDefinition([
            'builder_blocks' => [
                ['type' => 'question', 'data' => ['id' => 'page_break_1', 'question_type' => 'yes_no', 'label' => 'First question?']],
                ['type' => 'page_break', 'data' => []],
                ['type' => 'question', 'data' => ['id' => 'q2', 'question_type' => 'short_text', 'label' => 'Second question?']],
                ['type' => 'page_break', 'data' => ['id' => 'page_break_2']],
                ['type' => 'question', 'data' => ['id' => 'q3', 'question_type' => 'short_text', 'label' => 'Third question?']],
                ['type' => 'page_break', 'data' => ['id' => 'existing-break']],
                ['type' => 'question', 'data' => ['id' => 'q4', 'question_type' => 'short_text', 'label' => 'Fourth question?']],
                ['type' => 'page_break', 'data' => ['id' => 'existing-break']],
                ['type' => 'question', 'data' => ['id' => 'q5', 'question_type' => 'short_text', 'label' => 'Fifth question?']],
                ['type' => 'page_break', 'data' => ['id' => 'not valid']],
                ['type' => 'question', 'data' => ['id' => 'q6', 'question_type' => 'short_text', 'label' => 'Sixth question?']],
            ],
        ]);

        $this->assertSame(
            ['page_break_1', 'page_break_3', 'q2', 'page_break_2', 'q3', 'existing-break', 'q4', 'page_break_4', 'q5', 'page_break_5', 'q6'],
            array_column($definition['blocks'], 'id'),
        );
        app(QuizDefinitionValidator::class)->validate($definition);
    }

    public function test_stale_processing_analysis_is_requeued_once_and_dispatched_without_creating_a_duplicate(): void
    {
        Bus::fake();
        $analysis = $this->analysis([
            'status' => AnalysisStatus::Processing,
            'started_at' => now()->subMinutes(20),
            'heartbeat_at' => now()->subMinutes(20),
            'attempt_count' => 1,
        ]);

        $this->artisan('analyses:recover-stale')->assertSuccessful();
        $this->artisan('analyses:recover-stale')->assertSuccessful();

        $this->assertSame(1, Analysis::count());
        $this->assertSame(AnalysisStatus::Queued, $analysis->fresh()->status);
        $this->assertSame(1, $analysis->fresh()->recovery_count);
        Bus::assertDispatched(GenerateAnalysisJob::class, 1);
    }

    public function test_eligible_failed_analysis_is_retried_once_with_backoff_and_never_duplicates_the_analysis(): void
    {
        Bus::fake();
        $analysis = $this->analysis([
            'status' => AnalysisStatus::Failed,
            'attempt_count' => 1,
            'heartbeat_at' => now()->subMinutes(10),
        ]);

        $this->artisan('analyses:recover-stale')->assertSuccessful();
        $this->artisan('analyses:recover-stale')->assertSuccessful();

        $this->assertSame(1, Analysis::count());
        $this->assertSame(AnalysisStatus::Queued, $analysis->fresh()->status);
        $this->assertSame(1, $analysis->fresh()->recovery_count);
        Bus::assertDispatched(GenerateAnalysisJob::class, 1);
    }

    public function test_retry_limit_keeps_failed_analysis_terminal_and_does_not_dispatch_it(): void
    {
        Bus::fake();
        $analysis = $this->analysis([
            'status' => AnalysisStatus::Failed,
            'attempt_count' => 3,
            'heartbeat_at' => now()->subDay(),
        ]);

        $this->artisan('analyses:recover-stale')->assertSuccessful();

        $this->assertSame(AnalysisStatus::Failed, $analysis->fresh()->status);
        Bus::assertNotDispatched(GenerateAnalysisJob::class);
    }

    public function test_stale_or_failed_delivery_is_requeued_once_but_accepted_delivery_is_never_redispatched(): void
    {
        Bus::fake();
        $analysis = $this->analysis(['status' => AnalysisStatus::Completed]);
        $stale = ReportDelivery::create([
            'analysis_id' => $analysis->id, 'submission_id' => $analysis->submission_id,
            'recipient_email' => 'lead@example.test', 'status' => DeliveryStatus::Sending,
            'trigger' => 'automatic', 'automatic_key' => 'initial', 'queued_at' => now()->subMinutes(20),
            'sent_at' => now()->subMinutes(20),
        ]);
        $accepted = ReportDelivery::create([
            'analysis_id' => $this->analysis(['status' => AnalysisStatus::Completed])->id, 'submission_id' => $analysis->submission_id,
            'recipient_email' => 'lead@example.test', 'status' => DeliveryStatus::Accepted,
            'trigger' => 'automatic', 'automatic_key' => 'initial', 'queued_at' => now()->subMinutes(20),
        ]);

        $this->artisan('reports:recover-stale')->assertSuccessful();
        $this->artisan('reports:recover-stale')->assertSuccessful();

        $this->assertSame(2, ReportDelivery::count());
        $this->assertSame(DeliveryStatus::Queued, $stale->fresh()->status);
        $this->assertSame(DeliveryStatus::Accepted, $accepted->fresh()->status);
        Bus::assertDispatched(SendReportDeliveryJob::class, 1);
    }

    public function test_failed_delivery_is_retried_once_after_backoff_without_a_duplicate_record(): void
    {
        Bus::fake();
        $analysis = $this->analysis(['status' => AnalysisStatus::Completed]);
        $delivery = ReportDelivery::create([
            'analysis_id' => $analysis->id, 'submission_id' => $analysis->submission_id,
            'recipient_email' => 'lead@example.test', 'status' => DeliveryStatus::Failed,
            'trigger' => 'automatic', 'automatic_key' => 'initial', 'queued_at' => now()->subMinutes(20),
            'failed_at' => now()->subMinutes(20), 'attempt_count' => 1,
        ]);

        $this->artisan('reports:recover-stale')->assertSuccessful();
        $this->artisan('reports:recover-stale')->assertSuccessful();

        $this->assertSame(1, ReportDelivery::count());
        $this->assertSame(DeliveryStatus::Queued, $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->recovery_count);
        Bus::assertDispatched(SendReportDeliveryJob::class, 1);
    }

    public function test_recovered_analysis_fences_a_stale_worker_before_it_can_call_the_provider_or_clobber_completion(): void
    {
        Bus::fake();
        $analysis = $this->analysis([
            'status' => AnalysisStatus::Processing,
            'execution_generation' => 1,
            'execution_lease' => 'stale-lease',
            'lease_expires_at' => now()->subMinute(),
            'heartbeat_at' => now()->subMinutes(20),
        ]);
        $state = (object) ['calls' => 0];
        $generator = new class($state) implements QuizAnalysisGenerator
        {
            public function __construct(private object $state) {}

            public function generate(array $revision, array $answers, array $chain, string $systemPrompt): array
            {
                $this->state->calls++;

                return ['result' => ReportSchema::example(), 'provider' => 'fake', 'model' => 'fake', 'attempts' => []];
            }
        };

        $this->artisan('analyses:recover-stale')->assertSuccessful();
        (new GenerateAnalysisJob($analysis->id))->handle($generator, app(RequestReportDelivery::class));
        (new GenerateAnalysisJob($analysis->id, 'stale-lease'))->handle($generator, app(RequestReportDelivery::class));

        $this->assertSame(1, $state->calls);
        $this->assertSame(AnalysisStatus::Completed, $analysis->fresh()->status);
        $this->assertSame(1, $analysis->fresh()->recovery_count);
        $this->assertGreaterThan(1, $analysis->fresh()->execution_generation);
    }

    private function analysis(array $attributes = []): Analysis
    {
        $quiz = Quiz::factory()->create();
        $revision = QuizRevision::factory()->for($quiz)->create();
        $submission = Submission::factory()->for($quiz)->for($revision, 'quizRevision')->create(['email' => 'lead@example.test']);

        return Analysis::factory()->for($submission)->create(array_replace([
            'status' => AnalysisStatus::Queued,
            'trigger' => AnalysisTrigger::Automatic,
            'input_snapshot' => [],
            'requested_provider_chain' => [],
        ], $attributes));
    }
}

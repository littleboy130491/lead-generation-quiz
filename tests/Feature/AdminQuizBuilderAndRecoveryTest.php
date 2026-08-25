<?php

namespace Tests\Feature;

use App\Actions\Deliveries\RequestReportDelivery;
use App\Actions\Quizzes\PublishQuizRevision;
use App\Ai\Contracts\QuizAnalysisGenerator;
use App\Ai\Data\ReportSchema;
use App\Domain\Quiz\Pagination\QuizPageCompiler;
use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTrigger;
use App\Enums\DeliveryStatus;
use App\Filament\Resources\Quizzes\Schemas\QuizForm;
use App\Jobs\GenerateAnalysisJob;
use App\Jobs\SendReportDeliveryJob;
use App\Models\Analysis;
use App\Models\Quiz;
use App\Models\QuizRevision;
use App\Models\ReportDelivery;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminQuizBuilderAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_administrator_can_open_the_quiz_builder(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/quizzes/create')
            ->assertOk()
            ->assertSee('Quiz builder')
            ->assertSee('Access and lead settings');
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
            ->assertSee('Quiz builder');
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

    public function test_admin_builder_payload_creates_a_publishable_multi_page_public_quiz_without_storing_a_raw_password(): void
    {
        $payload = [
            'blocks' => [
                [
                    'type' => 'question',
                    'id' => 'q-readiness',
                    'question_type' => 'single_choice',
                    'label' => 'How ready are you?',
                    'help' => 'Choose one answer.',
                    'required' => true,
                    'options' => [['id' => 'ready', 'value' => 'ready', 'label' => 'Ready']],
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
        $this->assertTrue(Hash::check('secret password', $quiz->password_hash));
        $this->assertNotSame('secret password', $quiz->password_hash);
        $this->assertArrayNotHasKey('password_hash', QuizForm::toFormState($quiz));
        $this->post('/readiness-assessment/unlock', ['password' => 'secret password'])->assertRedirect('/readiness-assessment');
        $this->get('/readiness-assessment')->assertOk()->assertSee('How ready are you?')->assertSee('Page 1 of 2');
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

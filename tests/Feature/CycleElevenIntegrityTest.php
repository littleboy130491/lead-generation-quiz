<?php

namespace Tests\Feature;

use App\Actions\Analyses\RequestAnalysis;
use App\Actions\Deliveries\RequestReportDelivery;
use App\Actions\Quizzes\GenerateQuizDraft;
use App\Ai\Contracts\QuizAnalysisGenerator;
use App\Ai\Contracts\QuizDefinitionGenerator;
use App\Ai\Data\ReportSchema;
use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTrigger;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTrigger;
use App\Enums\SubmissionStatus;
use App\Jobs\GenerateAnalysisJob;
use App\Models\Analysis;
use App\Models\ApplicationSetting;
use App\Models\Quiz;
use App\Models\QuizDraftGeneration;
use App\Models\QuizRevision;
use App\Models\ReportDelivery;
use App\Models\Submission;
use App\Settings\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use LogicException;
use Tests\TestCase;

class CycleElevenIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_setting_model_writes_use_the_same_closed_validation_boundary(): void
    {
        foreach ([
            ['key' => 'unknown.group', 'value' => []],
            ['key' => 'design', 'value' => ['tokens' => [], 'additional_css' => '@import url(https://attacker.test/a.css);']],
            ['key' => 'prompts', 'value' => ['quiz_version' => 'v1', 'quiz_template' => '<?php echo 1;', 'report_version' => 'v1', 'report_template' => 'safe']],
            ['key' => 'operations', 'value' => ['resume_days' => 7, 'retention_days' => 90, 'retry_attempts' => 3, 'timeout_seconds' => 60, 'unknown' => true]],
        ] as $attributes) {
            try {
                ApplicationSetting::create($attributes);
                $this->fail('Direct model writes must be validated exactly like ApplicationSettings::put.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_manual_analysis_uses_request_time_chain_and_full_system_prompt_after_settings_change(): void
    {
        Bus::fake();
        $settings = app(ApplicationSettings::class);
        $settings->put('ai.report', [['provider' => 'original-provider', 'model' => 'original-model']]);
        $settings->put('prompts', ['quiz_version' => 'v1', 'quiz_template' => 'quiz', 'report_version' => 'request-v7', 'report_template' => 'Original report instruction.']);
        $analysis = app(RequestAnalysis::class)->handle($this->submission());

        $this->assertSame('request-v7', $analysis->prompt_version);
        $this->assertStringContainsString('Original report instruction.', $analysis->system_prompt_snapshot);

        $settings->put('ai.report', [['provider' => 'changed-provider', 'model' => 'changed-model']]);
        $settings->put('prompts', ['quiz_version' => 'v1', 'quiz_template' => 'quiz', 'report_version' => 'changed-v8', 'report_template' => 'Changed report instruction.']);
        $generator = new class implements QuizAnalysisGenerator
        {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function generate(array $revision, array $answers, array $chain, string $systemPrompt): array
            {
                $this->captured = compact('revision', 'answers', 'chain');

                return ['result' => ReportSchema::example(), 'provider' => $chain[0]['provider'], 'model' => $chain[0]['model'], 'attempts' => []];
            }
        };

        (new GenerateAnalysisJob($analysis->id))->handle($generator, app(RequestReportDelivery::class));

        $this->assertSame([['provider' => 'original-provider', 'model' => 'original-model']], $generator->captured['chain']);
        $this->assertSame(AnalysisStatus::Completed, $analysis->fresh()->status);
    }

    public function test_quiz_draft_generation_audits_and_uses_request_time_settings_after_they_change(): void
    {
        $settings = app(ApplicationSettings::class);
        $settings->put('ai.quiz', [['provider' => 'original-provider', 'model' => 'original-model']]);
        $settings->put('prompts', ['quiz_version' => 'quiz-v7', 'quiz_template' => 'Original quiz instruction.', 'report_version' => 'v1', 'report_template' => 'report']);
        $generator = new class implements QuizDefinitionGenerator
        {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function generate(array $brief, array $chain, string $systemPrompt): array
            {
                app(ApplicationSettings::class)->put('ai.quiz', [['provider' => 'changed-provider', 'model' => 'changed-model']]);
                app(ApplicationSettings::class)->put('prompts', ['quiz_version' => 'quiz-v8', 'quiz_template' => 'Changed quiz instruction.', 'report_version' => 'v1', 'report_template' => 'report']);
                $this->captured = compact('brief', 'chain', 'systemPrompt');

                return ['schema_version' => 1, 'blocks' => [['id' => 'goal', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready?']]];
            }
        };
        app()->instance(QuizDefinitionGenerator::class, $generator);
        $quiz = Quiz::factory()->create();

        app(GenerateQuizDraft::class)->handle($quiz, ['business_context' => 'Original brief']);

        $audit = QuizDraftGeneration::sole();
        $this->assertSame([['provider' => 'original-provider', 'model' => 'original-model']], $audit->requested_provider_chain);
        $this->assertSame('quiz-v7', $audit->prompt_version);
        $this->assertStringContainsString('Original quiz instruction.', $audit->system_prompt_snapshot);
        $this->assertSame('completed', $audit->status);
        $this->assertNotEmpty($audit->result_hash);
        $this->assertArrayNotHasKey('ai_request_snapshot', $quiz->fresh()->draft_definition);
        foreach ([
            fn () => $audit->update(['prompt_version' => 'tampered']),
            fn () => $audit->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Quiz generation audit request snapshots must be append-only.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame([['provider' => 'changed-provider', 'model' => 'changed-model']], $settings->get('ai.quiz'));
        $this->assertSame([['provider' => 'original-provider', 'model' => 'original-model']], $generator->captured['chain']);
        $this->assertStringContainsString('Original quiz instruction.', $generator->captured['systemPrompt']);
    }

    public function test_completed_historical_models_reject_direct_payload_mutation_and_deletion_but_allow_live_pii_anonymization(): void
    {
        $submission = $this->submission();
        $analysis = Analysis::factory()->for($submission)->create([
            'status' => AnalysisStatus::Completed,
            'trigger' => AnalysisTrigger::Manual,
            'input_snapshot' => ['answers' => ['frozen' => true]],
            'system_prompt_snapshot' => 'frozen prompt',
            'structured_result' => ReportSchema::example(),
            'completed_at' => now(),
        ]);
        $delivery = ReportDelivery::create([
            'analysis_id' => $analysis->id,
            'submission_id' => $submission->id,
            'recipient_email' => 'lead@example.test',
            'status' => DeliveryStatus::Accepted,
            'trigger' => DeliveryTrigger::Manual,
            'subject_snapshot' => 'Frozen subject',
            'html_snapshot' => '<p>Frozen</p>',
            'text_snapshot' => 'Frozen',
        ]);

        foreach ([
            fn () => $submission->update(['answers_snapshot' => ['tampered' => true]]),
            fn () => $submission->update(['quiz_revision_id' => 999999]),
            fn () => $analysis->update(['structured_result' => ['tampered' => true]]),
            fn () => $analysis->delete(),
            fn () => $delivery->update(['html_snapshot' => '<p>tampered</p>']),
            fn () => $delivery->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Historical payload and completed history must be append-only.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        $submission->fresh()->update(['email' => null, 'name' => null, 'company' => null, 'phone' => null, 'resume_token_hash' => null]);
        $this->assertNull($submission->fresh()->email);
    }

    public function test_delivery_webhook_lifecycle_transition_remains_allowed_but_terminal_content_stays_frozen(): void
    {
        $submission = $this->submission();
        $analysis = Analysis::factory()->for($submission)->create(['status' => AnalysisStatus::Completed, 'completed_at' => now()]);
        $delivery = ReportDelivery::create([
            'analysis_id' => $analysis->id,
            'submission_id' => $submission->id,
            'recipient_email' => 'lead@example.test',
            'status' => DeliveryStatus::Accepted,
            'trigger' => DeliveryTrigger::Manual,
            'subject_snapshot' => 'Frozen subject',
            'html_snapshot' => '<p>Frozen</p>',
            'text_snapshot' => 'Frozen',
        ]);

        $delivery->update(['status' => DeliveryStatus::Delivered, 'delivered_at' => now()]);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);

        $this->expectException(LogicException::class);
        $delivery->fresh()->update(['subject_snapshot' => 'Tampered']);
    }

    private function submission(): Submission
    {
        $quiz = Quiz::factory()->create();
        $revision = QuizRevision::factory()->for($quiz)->create(['definition' => ['schema_version' => 1, 'blocks' => []]]);

        return Submission::factory()->for($quiz)->for($revision, 'quizRevision')->create([
            'status' => SubmissionStatus::Completed,
            'email' => 'lead@example.test',
            'answers_snapshot' => ['readiness' => 'high'],
            'quiz_snapshot' => $revision->definition,
            'completed_at' => now(),
        ]);
    }
}

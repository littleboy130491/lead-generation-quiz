<?php

namespace Tests\Feature;

use App\Actions\Analyses\ManageAnalysis;
use App\Actions\Deliveries\ManageDelivery;
use App\Actions\Deliveries\RequestReportDelivery;
use App\Actions\Submissions\FinalizeSubmission;
use App\Actions\Submissions\ManageSubmission;
use App\Actions\Submissions\StartOrResumeSubmission;
use App\Ai\Data\ReportSchema;
use App\Enums\AnalysisStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTrigger;
use App\Enums\SubmissionStatus;
use App\Models\Analysis;
use App\Models\Quiz;
use App\Models\QuizRevision;
use App\Models\Submission;
use App\Models\User;
use App\Security\Turnstile\TurnstileVerification;
use App\Security\Turnstile\TurnstileVerifier;
use App\Settings\ApplicationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CycleTenRuntimeAndOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_persisted_report_chain_prompt_and_email_template_drive_created_analysis_and_delivery_snapshots(): void
    {
        Bus::fake();
        $settings = app(ApplicationSettings::class);
        $settings->put('ai.report', [['provider' => 'runtime-provider', 'model' => 'runtime-model']]);
        $settings->put('prompts', ['quiz_version' => 'v1', 'quiz_template' => 'quiz', 'report_version' => 'runtime-v2', 'report_template' => 'Use this runtime instruction.']);
        $settings->put('report.email', ['subject' => 'Report for {{email}}', 'html' => '<h1>{{report.executive_summary}}</h1>', 'text' => 'Summary: {{report.executive_summary}}']);
        $submission = $this->submission(['status' => SubmissionStatus::AwaitingContact]);

        app(FinalizeSubmission::class)->handle($submission, ['email' => 'lead@example.test']);
        $analysis = $submission->fresh()->analyses()->sole();
        $delivery = app(RequestReportDelivery::class)->handle($analysis->forceFill(['status' => AnalysisStatus::Completed, 'structured_result' => ReportSchema::example()]));

        $this->assertSame([['provider' => 'runtime-provider', 'model' => 'runtime-model']], $analysis->requested_provider_chain);
        $this->assertSame('runtime-v2', $analysis->prompt_version);
        $this->assertStringContainsString('Use this runtime instruction.', $analysis->system_prompt_snapshot);
        $this->assertSame('Report for lead@example.test', $delivery->subject_snapshot);
        $this->assertStringContainsString(ReportSchema::example()['executive_summary'], $delivery->html_snapshot);
    }

    public function test_settings_reject_unknown_nested_keys_unsafe_design_tokens_and_untrusted_email_template_syntax(): void
    {
        $settings = app(ApplicationSettings::class);

        foreach ([
            ['design', ['tokens' => ['background' => 'javascript:alert(1)'], 'additional_css' => 'body { color: red; }']],
            ['report.email', ['subject' => '<?php echo 1', 'html' => '{!! $unsafe !!}', 'text' => 'plain']],
            ['operations', ['resume_days' => 1, 'unknown' => true]],
        ] as [$key, $value]) {
            try {
                $settings->put($key, $value);
                $this->fail("{$key} should be rejected.");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_runtime_spam_policy_requires_turnstile_and_manual_mode_does_not_append_automatic_analysis(): void
    {
        Bus::fake();
        app(ApplicationSettings::class)->put('spam', ['turnstile_enabled' => true, 'analysis_mode' => 'manual']);
        app()->instance(TurnstileVerifier::class, new class implements TurnstileVerifier
        {
            public function verify(?string $token, ?string $ip = null): TurnstileVerification
            {
                return new TurnstileVerification($token === 'ok', 'rejected');
            }
        });
        $submission = $this->submission(['status' => SubmissionStatus::AwaitingContact]);

        $this->expectException(ValidationException::class);
        app(FinalizeSubmission::class)->handle($submission, ['email' => 'lead@example.test']);
    }

    public function test_runtime_operations_settings_drive_resume_and_recovery_limits(): void
    {
        app(ApplicationSettings::class)->put('operations', ['resume_days' => 7, 'retention_days' => 45, 'retry_attempts' => 1, 'timeout_seconds' => 120]);
        $quiz = Quiz::factory()->create();
        $revision = QuizRevision::factory()->for($quiz)->create();
        $quiz->update(['active_revision_id' => $revision->id]);
        [$started] = app(StartOrResumeSubmission::class)->handle($quiz);
        $failed = Analysis::factory()->for($started)->create(['status' => AnalysisStatus::Failed, 'attempt_count' => 1, 'heartbeat_at' => now()->subMinutes(10)]);

        $this->assertTrue($started->expires_at->between(now()->addDays(6)->startOfDay(), now()->addDays(8)->endOfDay()));
        $this->artisan('analyses:recover-stale')->assertSuccessful();
        $this->assertSame(AnalysisStatus::Failed, $failed->fresh()->status);
    }

    public function test_admin_operation_actions_are_authorized_and_append_history_without_mutating_snapshot(): void
    {
        $user = User::factory()->create();
        $submission = $this->submission(['status' => SubmissionStatus::Completed, 'email' => 'lead@example.test', 'answers_snapshot' => ['answer' => 'private']]);
        $analysis = Analysis::factory()->for($submission)->create(['status' => AnalysisStatus::Completed, 'structured_result' => ReportSchema::example()]);

        $this->get('/admin/submissions/'.$submission->id.'/edit')->assertRedirect('/admin/login');
        $this->actingAs($user)->get('/admin/submissions/'.$submission->id.'/edit')->assertOk()->assertSee('Mark spam')->assertSee('Anonymize');
        app(ManageSubmission::class)->markSpam($submission, $user->id);
        app(ManageSubmission::class)->hold($submission, $user->id);
        app(ManageAnalysis::class)->selectPreferred($analysis, $user->id);
        $delivery = app(RequestReportDelivery::class)->handle($analysis, DeliveryTrigger::Manual, $user->id);
        app(ManageDelivery::class)->cancel($delivery, $user->id);
        app(ManageSubmission::class)->anonymize($submission, $user->id);

        $this->assertSame(['answer' => 'private'], $submission->fresh()->answers_snapshot);
        $this->assertSame(['submission_id', 'quiz_id', 'revision_id', 'status', 'email', 'completed_at', 'analysis_count'], array_keys(app(ManageSubmission::class)->export($submission->fresh())));
        $this->assertSame($analysis->id, $submission->fresh()->preferred_analysis_id);
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        $this->assertGreaterThanOrEqual(4, $submission->events()->count());
    }

    private function submission(array $attributes = []): Submission
    {
        $quiz = Quiz::factory()->create();
        $revision = QuizRevision::factory()->for($quiz)->create(['definition' => ['title' => 'Readiness']]);

        return Submission::factory()->for($quiz)->for($revision, 'quizRevision')->create($attributes);
    }
}

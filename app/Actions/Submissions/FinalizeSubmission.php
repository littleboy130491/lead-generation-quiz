<?php

namespace App\Actions\Submissions;

use App\Ai\Prompt\AnalysisPromptBuilder;
use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTrigger;
use App\Enums\SubmissionStatus;
use App\Jobs\GenerateAnalysisJob;
use App\Models\Analysis;
use App\Models\Submission;
use App\Security\Turnstile\TurnstileVerifier;
use App\Services\SubmissionContext;
use App\Services\SubmissionEventRecorder;
use App\Settings\ApplicationSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinalizeSubmission
{
    public function __construct(private TurnstileVerifier $turnstile, private AnalysisPromptBuilder $prompts, private SubmissionContext $context, private SubmissionEventRecorder $events, private ApplicationSettings $settings) {}

    public function handle(Submission $submission, array $contact, ?string $ip = null, ?Request $request = null): Submission
    {
        $spam = $this->settings->get('spam');
        if ($spam['turnstile_enabled']) {
            $verification = $this->turnstile->verify($contact['turnstile_token'] ?? null, $ip);
            if (! $verification->accepted) {
                throw ValidationException::withMessages(['turnstile' => $verification->message ?? 'Verification failed.']);
            }
        }

        return DB::transaction(function () use ($submission, $contact, $request, $spam) {
            $s = Submission::lockForUpdate()->findOrFail($submission->id);
            if ($s->status === SubmissionStatus::Completed) {
                return $s;
            }
            if ($s->status !== SubmissionStatus::AwaitingContact) {
                throw ValidationException::withMessages(['submission' => 'Questionnaire must be completed first.']);
            }
            $email = strtolower(trim((string) ($contact['email'] ?? '')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['email' => 'A valid email address is required.']);
            }
            $s->update(['email' => $email, 'name' => $contact['name'] ?? null, 'company' => $contact['company'] ?? null, 'phone' => $contact['phone'] ?? null, 'status' => SubmissionStatus::Completed, 'completed_at' => now()]);
            $this->events->touch($s, $this->context->capture($request));
            $s->refresh();
            $this->events->record($s, 'completed');
            if ($spam['analysis_mode'] === 'always') {
                $prompt = $this->prompts->build($s->quizRevision->definition, $s->answers_snapshot ?? []);
                $configured = $this->settings->get('prompts');
                $analysis = Analysis::firstOrCreate(['submission_id' => $s->id, 'automatic_key' => 'initial'], ['public_id' => (string) Str::uuid(), 'sequence' => 1, 'status' => AnalysisStatus::Queued, 'trigger' => AnalysisTrigger::Automatic, 'created_manually' => false, 'requested_provider_chain' => $this->settings->get('ai.report'), 'prompt_version' => $configured['report_version'], 'system_prompt_snapshot' => $prompt->system, 'input_snapshot' => ['revision' => $s->quizRevision->definition, 'answers' => $s->answers_snapshot], 'queued_at' => now()]);
                if ($analysis->wasRecentlyCreated) {
                    $this->events->record($s, 'analysis_requested', ['analysis_id' => $analysis->id, 'trigger' => 'automatic']);
                    GenerateAnalysisJob::dispatch($analysis->id)->afterCommit();
                }
            }

            return $s;
        });
    }
}

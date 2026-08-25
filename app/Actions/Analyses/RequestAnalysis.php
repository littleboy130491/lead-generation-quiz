<?php

namespace App\Actions\Analyses;

use App\Ai\Prompt\AnalysisPromptBuilder;
use App\Enums\AnalysisStatus;
use App\Enums\AnalysisTrigger;
use App\Jobs\GenerateAnalysisJob;
use App\Models\Analysis;
use App\Models\Submission;
use App\Services\SubmissionEventRecorder;
use App\Settings\ApplicationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RequestAnalysis
{
    public function __construct(private SubmissionEventRecorder $events, private ApplicationSettings $settings, private AnalysisPromptBuilder $prompts) {}

    public function handle(Submission $submission, ?int $requestedBy = null, bool $sendWhenCompleted = false): Analysis
    {
        return DB::transaction(function () use ($submission, $requestedBy, $sendWhenCompleted) {
            $submission = Submission::lockForUpdate()->with('quizRevision')->findOrFail($submission->id);
            $configuredPrompts = $this->settings->get('prompts');
            $revision = $submission->quizRevision->definition;
            $answers = $submission->answers_snapshot ?? [];
            $prompt = $this->prompts->build($revision, $answers);
            $analysis = Analysis::create(['public_id' => (string) Str::uuid(), 'submission_id' => $submission->id, 'sequence' => ((int) $submission->analyses()->max('sequence')) + 1, 'status' => AnalysisStatus::Queued, 'trigger' => AnalysisTrigger::Manual, 'created_manually' => true, 'requested_by' => $requestedBy, 'requested_provider_chain' => $this->settings->get('ai.report', config('quiz.analysis_provider_chain', [])), 'prompt_version' => $configuredPrompts['report_version'], 'system_prompt_snapshot' => $prompt->system, 'input_snapshot' => ['revision' => $revision, 'answers' => $answers, 'send_when_completed' => $sendWhenCompleted], 'queued_at' => now()]);
            GenerateAnalysisJob::dispatch($analysis->id)->afterCommit();
            $this->events->record($submission, 'analysis_requested', ['analysis_id' => $analysis->id, 'trigger' => 'manual']);

            return $analysis;
        });
    }
}

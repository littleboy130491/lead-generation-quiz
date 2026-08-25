<?php

namespace App\Actions\Analyses;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\Submission;
use App\Services\SubmissionEventRecorder;

class ManageAnalysis
{
    public function __construct(private RequestAnalysis $requests, private SubmissionEventRecorder $events) {}

    public function retry(Analysis $analysis, ?int $actorId = null): Analysis
    {
        return $this->requests->handle($analysis->submission, $actorId);
    }

    public function cancel(Analysis $analysis, ?int $actorId = null): void
    {
        if (in_array($analysis->status, [AnalysisStatus::Queued, AnalysisStatus::Processing], true)) {
            $analysis->update(['status' => AnalysisStatus::Cancelled, 'cancelled_at' => now(), 'execution_lease' => null, 'lease_expires_at' => null]);
            $this->events->record($analysis->submission, 'analysis_cancelled', ['analysis_id' => $analysis->id, 'actor_id' => $actorId]);
        }
    }

    public function selectPreferred(Analysis $analysis, ?int $actorId = null): void
    {
        if ($analysis->status !== AnalysisStatus::Completed) {
            return;
        }
        Submission::query()->whereKey($analysis->submission_id)->update(['preferred_analysis_id' => $analysis->id]);
        $this->events->record($analysis->submission->fresh(), 'preferred_analysis_selected', ['analysis_id' => $analysis->id, 'actor_id' => $actorId]);
    }
}

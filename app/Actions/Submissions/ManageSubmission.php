<?php

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Services\SubmissionEventRecorder;

class ManageSubmission
{
    public function __construct(private SubmissionEventRecorder $events) {}

    public function markSpam(Submission $submission, ?int $actorId = null): void
    {
        $submission->update(['status' => SubmissionStatus::Spam]);
        $this->events->record($submission->fresh(), 'marked_spam', ['actor_id' => $actorId]);
    }

    public function hold(Submission $submission, ?int $actorId = null): void
    {
        $submission->update(['status' => SubmissionStatus::HeldForReview]);
        $this->events->record($submission->fresh(), 'held_for_review', ['actor_id' => $actorId]);
    }

    /** Deliberately leaves frozen answers, analyses, deliveries, and events intact. */
    public function anonymize(Submission $submission, ?int $actorId = null): void
    {
        $submission->update(['email' => null, 'name' => null, 'company' => null, 'phone' => null, 'resume_token_hash' => null]);
        $this->events->record($submission->fresh(), 'anonymized', ['actor_id' => $actorId]);
    }

    /** @return array<string, mixed> */
    public function export(Submission $submission): array
    {
        return [
            'submission_id' => $submission->public_id,
            'quiz_id' => $submission->quiz_id,
            'revision_id' => $submission->quiz_revision_id,
            'status' => $submission->status->value,
            'email' => $submission->email,
            'completed_at' => $submission->completed_at?->toIso8601String(),
            'analysis_count' => $submission->analyses()->count(),
        ];
    }
}

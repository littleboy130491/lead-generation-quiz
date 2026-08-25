<?php

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Models\Quiz;
use App\Models\Submission;
use App\Services\SubmissionContext;
use App\Services\SubmissionEventRecorder;
use App\Settings\ApplicationSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StartOrResumeSubmission
{
    public function __construct(
        private SubmissionContext $context,
        private SubmissionEventRecorder $events,
        private ApplicationSettings $settings,
    ) {}

    /** @return array{Submission, string} */
    public function handle(Quiz $quiz, ?string $token = null, ?Request $request = null): array
    {
        if (! $quiz->activeRevision) {
            abort(404);
        }

        $context = $this->context->capture($request);
        $submission = $token ? Submission::query()
            ->where('resume_token_hash', hash('sha256', $token))
            ->where('quiz_id', $quiz->id)
            ->where('status', SubmissionStatus::InProgress)
            ->where('expires_at', '>', now())
            ->first() : null;

        if ($submission) {
            $this->events->touch($submission, $context);
            $submission->refresh();
            $this->events->record($submission, 'resumed');

            return [$submission, $token];
        }

        $token = Str::random(64);
        $submission = Submission::create([
            'public_id' => (string) Str::uuid(),
            'quiz_id' => $quiz->id,
            'quiz_revision_id' => $quiz->active_revision_id,
            'resume_token_hash' => hash('sha256', $token),
            'status' => SubmissionStatus::InProgress,
            'answers_snapshot' => [],
            'metadata' => [],
            'first_touch_context' => $context,
            'latest_touch_context' => $context,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addDays($this->settings->operation('resume_days')),
        ]);
        $this->events->record($submission, 'started');

        return [$submission, $token];
    }
}

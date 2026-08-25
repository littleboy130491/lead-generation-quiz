<?php

namespace App\Services;

use App\Models\Submission;

class SubmissionEventRecorder
{
    /** @param array<string, mixed> $details */
    public function record(Submission $submission, string $event, array $details = []): void
    {
        $current = $submission->fresh();
        $context = $current?->latest_touch_context ?? $current?->first_touch_context ?? [];

        $submission->events()->create([
            'event' => $event,
            'context_snapshot' => json_decode(json_encode($context, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
            'details' => $details,
        ]);
    }

    /** @param array<string, mixed> $context */
    public function touch(Submission $submission, array $context): void
    {
        $submission->update([
            'latest_touch_context' => $context,
            'last_activity_at' => now(),
        ]);
    }
}

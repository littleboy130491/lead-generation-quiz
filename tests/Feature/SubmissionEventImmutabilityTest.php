<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\QuizRevision;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SubmissionEventImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_events_are_append_only_and_do_not_allow_mass_assignment_of_unrelated_fields(): void
    {
        $submission = $this->submission();
        $createdAt = now()->subDay();
        $event = $submission->events()->create([
            'event' => 'started',
            'context_snapshot' => ['query' => ['utm_source' => 'google']],
            'details' => ['page' => 0],
            'created_at' => $createdAt,
        ]);

        $this->assertTrue($event->created_at->isAfter($createdAt));
        $this->expectException(LogicException::class);
        $event->update(['event' => 'tampered']);
    }

    public function test_persisted_submission_events_cannot_be_deleted(): void
    {
        $event = $this->submission()->events()->create(['event' => 'started']);

        $this->expectException(LogicException::class);
        $event->delete();
    }

    private function submission(): Submission
    {
        $quiz = Quiz::factory()->create();
        $revision = QuizRevision::factory()->for($quiz)->create();

        return Submission::factory()->for($quiz)->for($revision, 'quizRevision')->create();
    }
}

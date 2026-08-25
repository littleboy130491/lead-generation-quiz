<?php

namespace Tests\Feature;

use App\Actions\Quizzes\PublishQuizRevision;
use App\Models\Quiz;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SubmissionAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_first_touch_is_preserved_when_a_facebook_resume_and_completion_update_latest_context(): void
    {
        Bus::fake();
        $quiz = Quiz::factory()->create(['draft_definition' => $this->definition()]);
        app(PublishQuizRevision::class)->handle($quiz);

        $this->withHeaders([
            'Referer' => 'https://www.google.com/search?q=assessment',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
        ])->get('/'.$quiz->slug.'?utm_source=google&utm_campaign=launch&api_key=do-not-store')->assertOk();

        $submission = Submission::firstOrFail();
        $this->assertSame('google', $submission->first_touch_context['query']['utm_source']);
        $this->assertArrayNotHasKey('api_key', $submission->first_touch_context['query']);
        $this->assertSame('Chrome', $submission->first_touch_context['client']['browser']);

        $this->withHeaders([
            'Referer' => 'https://facebook.com/ad?token=do-not-store',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Version/17.0 Mobile Safari/604.1',
        ])->get('/'.$quiz->slug.'?utm_source=facebook&utm_medium=paid_social&secret=do-not-store')->assertOk();

        $submission->refresh();
        $this->assertSame('google', $submission->first_touch_context['query']['utm_source']);
        $this->assertSame('facebook', $submission->latest_touch_context['query']['utm_source']);
        $this->assertArrayNotHasKey('secret', $submission->latest_touch_context['query']);
        $this->assertStringNotContainsString('do-not-store', (string) $submission->latest_touch_context['referrer']);
        $this->assertSame('https://facebook.com/ad', $submission->latest_touch_context['referrer']);
        $this->assertSame('mobile', $submission->latest_touch_context['client']['device']);

        $facebookHeaders = [
            'Referer' => 'https://facebook.com/ad?token=do-not-store',
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Version/17.0 Mobile Safari/604.1',
        ];
        $this->withHeaders($facebookHeaders)->post(route('submissions.save-page', [$submission, 0]).'?utm_source=facebook', ['answers' => ['q1' => 'yes'], 'direction' => 'next']);
        $this->withHeaders($facebookHeaders)->post(route('submissions.finalize', $submission).'?utm_source=facebook', ['email' => 'lead@example.test', 'website' => '']);

        $events = $submission->fresh()->events()->pluck('event')->all();
        $this->assertContains('started', $events);
        $this->assertContains('resumed', $events);
        $this->assertContains('page_saved', $events);
        $this->assertContains('questionnaire_completed', $events);
        $this->assertContains('completed', $events);
        $this->assertContains('analysis_requested', $events);
        $this->assertSame('google', $submission->fresh()->first_touch_context['query']['utm_source']);

        $facebookEvents = $submission->fresh()->events()
            ->whereIn('event', ['resumed', 'page_saved', 'questionnaire_completed', 'completed', 'analysis_requested'])
            ->get();
        $this->assertCount(5, $facebookEvents);
        foreach ($facebookEvents as $event) {
            $this->assertSame('facebook', $event->context_snapshot['query']['utm_source']);
        }
    }

    private function definition(): array
    {
        return ['schema_version' => 1, 'blocks' => [
            ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready?', 'required' => true],
        ]];
    }
}

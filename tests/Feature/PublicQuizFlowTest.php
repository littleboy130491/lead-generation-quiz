<?php

namespace Tests\Feature;

use App\Actions\Quizzes\PublishQuizRevision;
use App\Enums\SubmissionStatus;
use App\Models\Quiz;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublicQuizFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_runner_groups_questions_renders_safe_content_and_skips_empty_conditional_pages(): void
    {
        $quiz = $this->publishedQuiz();

        $response = $this->get('/'.$quiz->slug);

        $response->assertOk()
            ->assertSee('rel="stylesheet"', false)
            ->assertSee('First question')
            ->assertSee('Second question')
            ->assertSee('Read this safely')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('Conditional question')
            ->assertSee('Page 1 of 1');
        $this->assertDatabaseHas('submissions', ['quiz_id' => $quiz->id, 'current_page' => 0]);
        $this->assertStringNotContainsString('answers', (string) $response->headers->get('Set-Cookie'));
    }

    public function test_next_validates_typed_answers_persists_them_and_resumes_on_the_next_page(): void
    {
        $quiz = $this->publishedQuiz();
        $this->get('/'.$quiz->slug);
        $submission = Submission::firstOrFail();

        $this->post(route('submissions.save-page', [$submission, 0]), [
            'answers' => ['q1' => 'invalid', 'q2' => ''],
            'direction' => 'next',
        ])->assertSessionHasErrors(['answers.q1', 'answers.q2']);

        $this->post(route('submissions.save-page', [$submission, 0]), [
            'answers' => ['q1' => 'yes', 'q2' => ['one', 'two']],
            'direction' => 'next',
        ])->assertRedirect(route('quizzes.show', $quiz));

        $submission->refresh();
        $this->assertSame(1, $submission->current_page);
        $this->assertSame(['q1' => 'yes', 'q2' => ['one', 'two']], $submission->answers_snapshot);
        $this->get('/'.$quiz->slug)->assertSee('Conditional question')->assertSee('Page 2 of 2');
    }

    public function test_unknown_current_page_answer_id_is_rejected_without_mutating_the_submission(): void
    {
        $quiz = $this->publishedQuiz();
        $this->get('/'.$quiz->slug);
        $submission = Submission::firstOrFail();
        $before = [
            'status' => $submission->status,
            'answers' => $submission->answers_snapshot,
            'current_page' => $submission->current_page,
            'last_activity_at' => $submission->last_activity_at,
            'latest_touch_context' => $submission->latest_touch_context,
            'event_count' => $submission->events()->count(),
            'analysis_count' => $submission->analyses()->count(),
        ];

        $this->post(route('submissions.save-page', [$submission, 0]), [
            'answers' => ['q1' => 'yes', 'q2' => ['one'], 'unknown-question' => 'attacker-value'],
            'direction' => 'next',
        ])->assertSessionHasErrors('answers.unknown-question');

        $submission->refresh();
        $this->assertSame($before['status'], $submission->status);
        $this->assertSame($before['answers'], $submission->answers_snapshot);
        $this->assertSame($before['current_page'], $submission->current_page);
        $this->assertEquals($before['last_activity_at'], $submission->last_activity_at);
        $this->assertSame($before['latest_touch_context'], $submission->latest_touch_context);
        $this->assertSame($before['event_count'], $submission->events()->count());
        $this->assertSame($before['analysis_count'], $submission->analyses()->count());
    }

    public function test_content_only_page_can_continue_and_contact_is_only_available_after_questionnaire(): void
    {
        $definition = $this->definition();
        $definition['blocks'] = [
            $definition['blocks'][0],
            $definition['blocks'][1],
            ['id' => 'break-content', 'type' => 'page_break'],
            ['id' => 'content-only', 'type' => 'content', 'markdown' => 'Take a breath'],
            ['id' => 'break-last', 'type' => 'page_break'],
            $definition['blocks'][4],
        ];
        $quiz = $this->publishedQuiz($definition);
        $this->get('/'.$quiz->slug);
        $submission = Submission::firstOrFail();

        $this->get(route('quizzes.contact', [$quiz, $submission]))->assertForbidden();
        $this->post(route('submissions.save-page', [$submission, 0]), [
            'answers' => ['q1' => 'no', 'q2' => ['one']], 'direction' => 'next',
        ])->assertRedirect(route('quizzes.show', $quiz));
        $this->get('/'.$quiz->slug)->assertSee('Take a breath')->assertSee('Continue');
        $this->post(route('submissions.save-page', [$submission, 1]), ['answers' => [], 'direction' => 'next'])
            ->assertRedirect(route('quizzes.contact', [$quiz, $submission]));
    }

    public function test_password_protected_quiz_requires_an_unlock_session_before_a_submission_is_created(): void
    {
        $quiz = $this->publishedQuiz();
        $quiz->update(['password_hash' => Hash::make('correct horse')]);

        $this->get('/'.$quiz->slug)->assertOk()->assertSee('Unlock this quiz');
        $this->assertDatabaseCount('submissions', 0);
        $this->post(route('quizzes.unlock', $quiz), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post(route('quizzes.unlock', $quiz), ['password' => 'correct horse'])
            ->assertRedirect(route('quizzes.show', $quiz));
        $this->get('/'.$quiz->slug)->assertOk()->assertSee('First question');
        $this->assertDatabaseCount('submissions', 1);
    }

    public function test_contact_capture_completes_an_authorized_submission_and_shows_completion_screen(): void
    {
        Bus::fake();
        $quiz = $this->publishedQuiz();
        $this->get('/'.$quiz->slug);
        $submission = Submission::firstOrFail();
        $this->post(route('submissions.save-page', [$submission, 0]), [
            'answers' => ['q1' => 'yes', 'q2' => ['one']], 'direction' => 'next',
        ]);

        $this->post(route('submissions.save-page', [$submission, 1]), ['answers' => ['q3' => 'A detail'], 'direction' => 'next'])
            ->assertRedirect(route('quizzes.contact', [$quiz, $submission]));
        $this->get(route('quizzes.contact', [$quiz, $submission]))
            ->assertOk()
            ->assertSee('rel="stylesheet"', false)
            ->assertSee('Where should we send your report?');
        $this->post(route('submissions.finalize', $submission), ['email' => 'Lead@Example.test', 'website' => ''])
            ->assertRedirect(route('quizzes.complete', [$quiz, $submission]));
        $this->get(route('quizzes.complete', [$quiz, $submission]))->assertOk()->assertSee('Thank you');
        $this->assertSame(SubmissionStatus::Completed, $submission->fresh()->status);
        $this->assertSame('lead@example.test', $submission->fresh()->email);
    }

    public function test_removed_direct_questionnaire_endpoint_cannot_bypass_current_page_validation_or_mutate_a_submission(): void
    {
        $quiz = $this->publishedQuiz();
        $this->get('/'.$quiz->slug);
        $submission = Submission::firstOrFail();
        $before = [
            'status' => $submission->status,
            'answers' => $submission->answers_snapshot,
            'latest_touch_context' => $submission->latest_touch_context,
            'event_count' => $submission->events()->count(),
        ];

        foreach ([
            ['unknown-question' => 'attacker-value'],
            ['q3' => 'invisible answer'],
            ['q1' => ['yes']],
            ['q1' => 'yes', 'q2' => ['not-an-option']],
            ['q1' => 'yes', 'q2' => ['one'], 'q3' => str_repeat('x', 256)],
        ] as $answers) {
            $this->post('/submissions/'.$submission->getRouteKey().'/questionnaire', ['answers' => $answers])
                ->assertStatus(405);

            $submission->refresh();
            $this->assertSame($before['status'], $submission->status);
            $this->assertSame($before['answers'], $submission->answers_snapshot);
            $this->assertSame($before['latest_touch_context'], $submission->latest_touch_context);
            $this->assertSame($before['event_count'], $submission->events()->count());
        }
    }

    public function test_reserved_quiz_slug_is_rejected_before_it_can_shadow_a_public_route(): void
    {
        $this->expectException(ValidationException::class);

        Quiz::factory()->create(['slug' => 'admin']);
    }

    private function publishedQuiz(?array $definition = null): Quiz
    {
        $quiz = Quiz::factory()->create(['draft_definition' => $definition ?? $this->definition()]);
        app(PublishQuizRevision::class)->handle($quiz);

        return $quiz->fresh();
    }

    private function definition(): array
    {
        return ['schema_version' => 1, 'blocks' => [
            ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'First question', 'required' => true],
            ['id' => 'q2', 'type' => 'question', 'question_type' => 'multiple_choice', 'label' => 'Second question', 'required' => true, 'options' => [
                ['id' => 'one', 'value' => 'one', 'label' => 'One'], ['id' => 'two', 'value' => 'two', 'label' => 'Two'],
            ]],
            ['id' => 'info', 'type' => 'content', 'markdown' => "Read this safely\n<script>alert(1)</script>"],
            ['id' => 'break-1', 'type' => 'page_break'],
            ['id' => 'q3', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Conditional question', 'required' => true, 'visibility' => ['question_id' => 'q1', 'operator' => 'equals', 'value' => 'yes']],
        ]];
    }
}

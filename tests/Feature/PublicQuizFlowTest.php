<?php

namespace Tests\Feature;

use App\Actions\Quizzes\PublishQuizRevision;
use App\Enums\SubmissionStatus;
use App\Jobs\GenerateAnalysisJob;
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
            ->assertSee('quiz-required', false)
            ->assertSee('Second question')
            ->assertSee('type="checkbox"', false)
            ->assertSee('answers[q2][]', false)
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
            ->assertSee('Where should we send your report?')
            ->assertSee('for="email"', false)
            ->assertDontSee('for="name"', false)
            ->assertDontSee('for="company"', false)
            ->assertDontSee('for="phone"', false);
        $this->post(route('submissions.finalize', $submission), [
            'email' => 'Lead@Example.test',
            'website' => '',
            'name' => 'Should Be Ignored',
            'company' => 'Acme',
            'phone' => '555-0100',
        ])->assertRedirect(route('quizzes.complete', [$quiz, $submission]));
        $this->get(route('quizzes.complete', [$quiz, $submission]))->assertOk()->assertSee('Thank you');
        $this->assertSame(SubmissionStatus::Completed, $submission->fresh()->status);
        $this->assertSame('lead@example.test', $submission->fresh()->email);
        $this->assertNull($submission->fresh()->name);
        $this->assertNull($submission->fresh()->company);
        $this->assertNull($submission->fresh()->phone);
    }

    public function test_contact_form_shows_only_enabled_lead_capture_fields_and_persists_them(): void
    {
        Bus::fake();
        $quiz = $this->publishedQuiz();
        $quiz->update(['settings' => ['collect_name' => true, 'collect_company' => false, 'collect_phone' => true]]);
        $this->get('/'.$quiz->slug);
        $submission = Submission::firstOrFail();
        $this->post(route('submissions.save-page', [$submission, 0]), [
            'answers' => ['q1' => 'yes', 'q2' => ['one']], 'direction' => 'next',
        ]);
        $this->post(route('submissions.save-page', [$submission, 1]), ['answers' => ['q3' => 'A detail'], 'direction' => 'next']);

        $this->get(route('quizzes.contact', [$quiz, $submission]))
            ->assertOk()
            ->assertSee('for="name"', false)
            ->assertSee('for="phone"', false)
            ->assertDontSee('for="company"', false);

        $this->post(route('submissions.finalize', $submission), [
            'email' => 'lead@example.test',
            'name' => 'Ada Lovelace',
            'company' => 'Should Be Ignored',
            'phone' => '555-0100',
        ])->assertRedirect(route('quizzes.complete', [$quiz, $submission]));

        $completed = $submission->fresh();
        $this->assertSame('Ada Lovelace', $completed->name);
        $this->assertNull($completed->company);
        $this->assertSame('555-0100', $completed->phone);
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

    public function test_gated_opening_shows_start_button_before_questions_and_back_returns_to_opening(): void
    {
        $definition = $this->definition();
        $definition['opening'] = [
            'html' => '<h2>Welcome opener</h2><p>Read this first.</p><script>alert(1)</script>',
            'start_button_label' => 'Start my quiz',
            'hide_start_button' => false,
        ];
        $quiz = $this->publishedQuiz($definition);

        $this->get('/'.$quiz->slug)
            ->assertOk()
            ->assertSee('Welcome opener')
            ->assertSee('Start my quiz')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('First question');

        $submission = Submission::firstOrFail();
        $this->assertFalse((bool) data_get($submission->metadata, 'opening_dismissed'));

        $this->post(route('submissions.dismiss-opening', $submission))
            ->assertRedirect(route('quizzes.show', $quiz));

        $submission->refresh();
        $this->assertTrue((bool) data_get($submission->metadata, 'opening_dismissed'));
        $this->get('/'.$quiz->slug)
            ->assertOk()
            ->assertSee('First question')
            ->assertDontSee('Welcome opener')
            ->assertDontSee('Start my quiz');

        $this->post(route('submissions.save-page', [$submission, 0]), [
            'answers' => [],
            'direction' => 'back',
        ])->assertRedirect(route('quizzes.show', $quiz));

        $submission->refresh();
        $this->assertFalse((bool) data_get($submission->metadata, 'opening_dismissed'));
        $this->get('/'.$quiz->slug)->assertSee('Welcome opener')->assertSee('Start my quiz')->assertDontSee('First question');
    }

    public function test_inline_opening_renders_above_first_page_without_a_start_button(): void
    {
        $definition = $this->definition();
        $definition['opening'] = [
            'html' => '<h2>Inline opener</h2><p>Questions begin below.</p>',
            'start_button_label' => 'Unused label',
            'hide_start_button' => true,
        ];
        $quiz = $this->publishedQuiz($definition);

        $this->get('/'.$quiz->slug)
            ->assertOk()
            ->assertSee('Inline opener')
            ->assertSee('Questions begin below.')
            ->assertSee('First question')
            ->assertDontSee('Unused label')
            ->assertDontSee('Start quiz');
    }

    public function test_page_answers_are_rejected_while_a_gated_opening_is_pending(): void
    {
        $definition = $this->definition();
        $definition['opening'] = [
            'html' => '<p>Please start</p>',
            'start_button_label' => 'Start quiz',
            'hide_start_button' => false,
        ];
        $quiz = $this->publishedQuiz($definition);
        $this->get('/'.$quiz->slug);
        $submission = Submission::firstOrFail();

        $this->post(route('submissions.save-page', [$submission, 0]), [
            'answers' => ['q1' => 'yes', 'q2' => ['one']],
            'direction' => 'next',
        ])->assertSessionHasErrors('opening');
    }

    public function test_questionnaire_completion_stores_score_total_and_matched_result(): void
    {
        $definition = $this->definition();
        $definition['blocks'][0]['yes_score'] = 2;
        $definition['blocks'][0]['no_score'] = 0;
        $definition['blocks'][1]['options'][0]['score'] = 1;
        $definition['blocks'][1]['options'][1]['score'] = 4;
        $definition['result'] = ['mode' => 'score'];
        $definition['thank_you'] = ['enabled' => false];
        $definition['score_results'] = [
            ['id' => 'starter', 'title' => 'Starter profile', 'min_score' => 0, 'max_score' => 4, 'html' => '<p>Keep building</p><script>alert(1)</script>'],
            ['id' => 'ready', 'title' => 'Ready profile', 'min_score' => 5, 'max_score' => 20, 'html' => '<p>You are ready</p>'],
        ];
        $quiz = $this->publishedQuiz($definition);
        $this->get('/'.$quiz->slug);
        $submission = Submission::firstOrFail();

        $this->post(route('submissions.save-page', [$submission, 0]), [
            'answers' => ['q1' => 'yes', 'q2' => ['two']],
            'direction' => 'next',
        ]);
        $this->post(route('submissions.save-page', [$submission->fresh(), 1]), [
            'answers' => ['q3' => 'detail'],
            'direction' => 'next',
        ])->assertRedirect(route('quizzes.contact', [$quiz, $submission]));

        $submission->refresh();
        $this->assertSame(6, data_get($submission->metadata, 'scoring.total'));
        $this->assertSame('ready', data_get($submission->metadata, 'scoring.result.id'));
        $this->assertSame('Ready profile', data_get($submission->metadata, 'scoring.result.title'));

        $this->get(route('quizzes.contact', [$quiz, $submission]))
            ->assertOk()
            ->assertSee('Ready profile')
            ->assertSee('You are ready')
            ->assertDontSee('<script>alert(1)</script>', false);

        Bus::fake();
        $this->post(route('submissions.finalize', $submission), [
            'email' => 'lead@example.test',
            'website' => '',
        ])->assertRedirect(route('quizzes.complete', [$quiz, $submission]));
        Bus::assertNotDispatched(GenerateAnalysisJob::class);
        $this->get(route('quizzes.complete', [$quiz, $submission]))
            ->assertOk()
            ->assertSee('Ready profile')
            ->assertSee('You are ready')
            ->assertDontSee('Thank you');
    }

    public function test_question_image_and_icon_are_rendered_on_the_public_runner(): void
    {
        $definition = $this->definition();
        $definition['blocks'][0]['image_url'] = 'https://cdn.example.test/first.png';
        $definition['blocks'][0]['icon'] = '⭐';
        $quiz = $this->publishedQuiz($definition);

        $this->get('/'.$quiz->slug)
            ->assertOk()
            ->assertSee('https://cdn.example.test/first.png', false)
            ->assertSee('⭐')
            ->assertSee('First question');
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

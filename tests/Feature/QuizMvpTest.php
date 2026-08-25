<?php

namespace Tests\Feature;

use App\Actions\Quizzes\PublishQuizRevision;
use App\Actions\Submissions\CompleteQuestionnaire;
use App\Actions\Submissions\FinalizeSubmission;
use App\Actions\Submissions\StartOrResumeSubmission;
use App\Domain\Quiz\Conditions\ConditionEvaluator;
use App\Domain\Quiz\Pagination\QuizPageCompiler;
use App\Enums\AnalysisStatus;
use App\Enums\QuizStatus;
use App\Enums\SubmissionStatus;
use App\Jobs\GenerateAnalysisJob;
use App\Models\Analysis;
use App\Models\Quiz;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class QuizMvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_compiles_immutable_revision_and_rejects_bad_page_breaks(): void
    {
        $quiz = Quiz::factory()->create(['draft_definition' => $this->definition()]);

        $revision = app(PublishQuizRevision::class)->handle($quiz);

        $this->assertSame(QuizStatus::Published, $quiz->fresh()->status);
        $this->assertSame(1, $revision->version);
        $this->assertSame($this->definition(), $revision->definition);
        $this->assertCount(2, app(QuizPageCompiler::class)->compile($revision->definition));
    }

    public function test_conditions_and_questionnaire_completion_are_server_authoritative(): void
    {
        $definition = $this->definition();
        $definition['blocks'][2]['visibility'] = ['question_id' => 'q1', 'operator' => 'equals', 'value' => 'yes'];
        $quiz = Quiz::factory()->create(['draft_definition' => $definition]);
        $revision = app(PublishQuizRevision::class)->handle($quiz);
        $submission = Submission::factory()->for($quiz)->for($revision, 'quizRevision')->create(['status' => SubmissionStatus::InProgress]);

        $this->assertFalse(app(ConditionEvaluator::class)->visible($definition['blocks'][2]['visibility'], ['q1' => 'no']));
        app(CompleteQuestionnaire::class)->handle($submission, ['q1' => 'no']);

        $this->assertSame(SubmissionStatus::AwaitingContact, $submission->fresh()->status);
    }

    public function test_resume_token_is_opaque_and_finalization_creates_only_one_automatic_analysis(): void
    {
        Bus::fake();
        $quiz = Quiz::factory()->create(['draft_definition' => $this->definition()]);
        $revision = app(PublishQuizRevision::class)->handle($quiz);
        [$submission, $token] = app(StartOrResumeSubmission::class)->handle($quiz);
        $this->assertNotSame($token, $submission->resume_token_hash);

        app(CompleteQuestionnaire::class)->handle($submission, ['q1' => 'yes', 'q2' => 'detail']);
        app(FinalizeSubmission::class)->handle($submission, ['email' => 'Lead@Example.test']);
        app(FinalizeSubmission::class)->handle($submission->fresh(), ['email' => 'lead@example.test']);

        $this->assertSame(SubmissionStatus::Completed, $submission->fresh()->status);
        $this->assertSame('lead@example.test', $submission->fresh()->email);
        $this->assertSame(1, Analysis::query()->where('submission_id', $submission->id)->count());
        $this->assertSame(AnalysisStatus::Queued, $submission->analyses()->first()->status);
        Bus::assertDispatched(GenerateAnalysisJob::class);
    }

    public function test_finalizing_different_submissions_creates_an_initial_automatic_analysis_for_each(): void
    {
        Bus::fake();
        $quiz = Quiz::factory()->create(['draft_definition' => $this->definition()]);
        app(PublishQuizRevision::class)->handle($quiz);

        foreach (['first@example.test', 'second@example.test'] as $email) {
            [$submission] = app(StartOrResumeSubmission::class)->handle($quiz);
            app(CompleteQuestionnaire::class)->handle($submission, ['q1' => 'yes', 'q2' => 'detail']);
            app(FinalizeSubmission::class)->handle($submission, ['email' => $email]);
        }

        $this->assertSame(2, Analysis::where('automatic_key', 'initial')->count());
    }

    public function test_published_revision_cannot_have_its_payload_updated_or_be_deleted(): void
    {
        $quiz = Quiz::factory()->create(['draft_definition' => $this->definition()]);
        $revision = app(PublishQuizRevision::class)->handle($quiz);

        try {
            $revision->update(['definition' => ['schema_version' => 1, 'blocks' => []]]);
            $this->fail('Published revision payload update was allowed.');
        } catch (\LogicException) {
            $this->assertSame($this->definition(), $revision->fresh()->definition);
        }

        $this->expectException(\LogicException::class);
        $revision->delete();
    }

    private function definition(): array
    {
        return ['schema_version' => 1, 'blocks' => [
            ['id' => 'q1', 'type' => 'question', 'question_type' => 'yes_no', 'label' => 'Ready?', 'required' => true],
            ['id' => 'break-1', 'type' => 'page_break'],
            ['id' => 'q2', 'type' => 'question', 'question_type' => 'short_text', 'label' => 'Tell us', 'required' => true],
        ]];
    }
}

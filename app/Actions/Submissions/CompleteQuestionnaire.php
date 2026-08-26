<?php

namespace App\Actions\Submissions;

use App\Domain\Quiz\Conditions\ConditionEvaluator;
use App\Domain\Quiz\Result\QuizResultConfig;
use App\Domain\Quiz\Scoring\QuizScoreCalculator;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Services\SubmissionContext;
use App\Services\SubmissionEventRecorder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompleteQuestionnaire
{
    public function __construct(
        private ConditionEvaluator $conditions,
        private QuizScoreCalculator $scores,
        private SubmissionContext $context,
        private SubmissionEventRecorder $events,
    ) {}

    public function handle(Submission $submission, array $answers, ?Request $request = null): Submission
    {
        if ($submission->status !== SubmissionStatus::InProgress) {
            return $submission;
        }

        $definition = $submission->quizRevision->definition ?? [];
        foreach ($definition['blocks'] ?? [] as $block) {
            if (($block['type'] ?? '') !== 'question' || ! $this->conditions->visible($block['visibility'] ?? null, $answers)) {
                continue;
            }
            $answer = $answers[$block['id']] ?? null;
            if (($block['required'] ?? false) && ($answer === null || $answer === '' || $answer === [])) {
                throw ValidationException::withMessages(['answers.'.$block['id'] => 'This answer is required.']);
            }
        }

        $metadata = $submission->metadata ?? [];
        $scoring = QuizResultConfig::usesScoreResults($definition)
            ? $this->scores->calculate($definition, $answers)
            : null;
        if ($scoring === null) {
            unset($metadata['scoring']);
        } else {
            $metadata['scoring'] = $scoring;
        }

        $submission->update([
            'answers_snapshot' => $answers,
            'metadata' => $metadata,
            'status' => SubmissionStatus::AwaitingContact,
            'questionnaire_completed_at' => now(),
            'last_activity_at' => now(),
        ]);
        $submission->refresh();
        $this->events->touch($submission, $this->context->capture($request));
        $submission->refresh();
        $this->events->record($submission, 'questionnaire_completed', $scoring === null ? [] : ['scoring_total' => $scoring['total']]);

        return $submission;
    }
}

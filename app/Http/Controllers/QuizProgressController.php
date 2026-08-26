<?php

namespace App\Http\Controllers;

use App\Domain\Quiz\Result\QuizResultConfig;
use App\Enums\SubmissionStatus;
use App\Models\Quiz;
use App\Models\Submission;
use App\Services\CompletionHtmlSanitizer;
use App\Settings\BrandingSettings;

class QuizProgressController extends Controller
{
    public function contact(Quiz $quiz, Submission $submission, CompletionHtmlSanitizer $html, BrandingSettings $branding)
    {
        abort_unless($submission->quiz_id === $quiz->id && $submission->status === SubmissionStatus::AwaitingContact, 403);

        $definition = $submission->quizRevision->definition ?? [];
        $scoreResult = QuizResultConfig::usesScoreResults($definition)
            ? data_get($submission->metadata, 'scoring.result')
            : null;
        $scoreResultHtml = is_array($scoreResult) && filled($scoreResult['html'] ?? null)
            ? $html->sanitize((string) $scoreResult['html'])
            : '';

        return view('quiz.contact', compact('quiz', 'submission', 'scoreResult', 'scoreResultHtml', 'branding'));
    }
}

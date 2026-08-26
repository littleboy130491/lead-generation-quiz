<?php

namespace App\Http\Controllers;

use App\Actions\Submissions\FinalizeSubmission;
use App\Domain\Quiz\Result\QuizResultConfig;
use App\Enums\SubmissionStatus;
use App\Models\Quiz;
use App\Models\Submission;
use App\Services\CompletionHtmlSanitizer;
use App\Settings\BrandingSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuizContactController extends Controller
{
    public function store(Request $request, Submission $submission, FinalizeSubmission $finalize): RedirectResponse
    {
        abort_if($request->filled('website'), 422);
        $quiz = $submission->quiz;
        $rules = [
            'email' => ['required', 'email:rfc', 'max:255'],
            'turnstile_token' => ['nullable', 'string', 'max:4096'],
        ];
        foreach (['name', 'company', 'phone'] as $field) {
            if ($quiz->collectsContactField($field)) {
                $rules[$field] = ['nullable', 'string', 'max:255'];
            }
        }
        $contact = $request->validate($rules);
        $finalize->handle($submission, $contact, $request->ip(), $request);

        return redirect()->route('quizzes.complete', ['quiz' => $submission->quiz, 'submission' => $submission]);
    }

    public function complete(Quiz $quiz, Submission $submission, BrandingSettings $branding, CompletionHtmlSanitizer $completionHtml): View
    {
        abort_unless($submission->quiz_id === $quiz->id && $submission->status === SubmissionStatus::Completed, 403);

        $definition = $submission->quizRevision->definition ?? [];
        $thankYouEnabled = QuizResultConfig::thankYouEnabled($definition);
        $override = QuizResultConfig::thankYouOverrideHtml($definition);

        if ($thankYouEnabled) {
            $html = $override ?? $branding->completion_html;
            $title = 'Thank you';
        } else {
            $result = data_get($submission->metadata, 'scoring.result');
            $title = is_array($result) ? (string) ($result['title'] ?? 'Your result') : 'Your result';
            $html = is_array($result) && filled($result['html'] ?? null)
                ? (string) $result['html']
                : '<h1>'.e($title).'</h1><p>Thanks for completing this quiz.</p>';
        }

        return view('quiz.complete', [
            'quiz' => $quiz,
            'submission' => $submission,
            'pageTitle' => $title,
            'completionHtml' => $completionHtml->sanitize($html),
        ]);
    }
}

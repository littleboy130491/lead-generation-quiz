<?php

namespace App\Http\Controllers;

use App\Actions\Submissions\FinalizeSubmission;
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
        $contact = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'turnstile_token' => ['nullable', 'string', 'max:4096'],
        ]);
        $finalize->handle($submission, $contact, $request->ip(), $request);

        return redirect()->route('quizzes.complete', ['quiz' => $submission->quiz, 'submission' => $submission]);
    }

    public function complete(Quiz $quiz, Submission $submission, BrandingSettings $branding, CompletionHtmlSanitizer $completionHtml): View
    {
        abort_unless($submission->quiz_id === $quiz->id && $submission->status === SubmissionStatus::Completed, 403);

        return view('quiz.complete', [
            'quiz' => $quiz,
            'submission' => $submission,
            'completionHtml' => $completionHtml->sanitize($branding->completion_html),
        ]);
    }
}

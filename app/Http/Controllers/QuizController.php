<?php

namespace App\Http\Controllers;

use App\Actions\Submissions\SaveQuizPage;
use App\Actions\Submissions\StartOrResumeSubmission;
use App\Domain\Quiz\Pagination\VisibleQuizPages;
use App\Enums\SubmissionStatus;
use App\Models\Quiz;
use App\Models\Submission;
use App\Services\SubmissionContext;
use App\Services\SubmissionEventRecorder;
use App\Settings\ApplicationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class QuizController extends Controller
{
    public function show(Request $request, Quiz $quiz, StartOrResumeSubmission $start, ApplicationSettings $settings)
    {
        abort_unless($quiz->status->value === 'published' && $quiz->activeRevision, 404);
        if ($quiz->password_hash && ! $this->isUnlocked($request, $quiz)) {
            return view('quiz.unlock', compact('quiz'));
        }

        $existing = Submission::query()
            ->whereKey($request->session()->get('quiz_submission.'.$quiz->id))
            ->where('quiz_id', $quiz->id)
            ->where('status', SubmissionStatus::InProgress)
            ->where('expires_at', '>', now())
            ->first();
        if ($existing) {
            app(SubmissionEventRecorder::class)->touch($existing, app(SubmissionContext::class)->capture($request));
            $existing->refresh();
            app(SubmissionEventRecorder::class)->record($existing, 'resumed');
            [$submission, $token] = [$existing, $request->cookie('quiz_resume_'.$quiz->id)];
        } else {
            [$submission, $token] = $start->handle($quiz, $request->cookie('quiz_resume_'.$quiz->id), $request);
        }
        $request->session()->put('quiz_submission.'.$quiz->id, $submission->id);

        $pages = app(VisibleQuizPages::class)->forSubmission($submission, $submission->answers_snapshot ?? []);
        if (! isset($pages[$submission->current_page])) {
            $submission->update(['current_page' => 0]);
            $submission->refresh();
        }
        $page = $pages[$submission->current_page] ?? [];
        $design = $settings->get('design');
        $response = response()->view('quiz.show', compact('quiz', 'submission', 'pages', 'page', 'design'));
        if ($token) {
            $response->cookie('quiz_resume_'.$quiz->id, $token, 60 * 24 * $settings->operation('resume_days'), null, null, app()->environment('production'), true, false, 'lax');
        }

        return $response;
    }

    public function unlock(Request $request, Quiz $quiz): RedirectResponse
    {
        abort_unless($quiz->status->value === 'published' && $quiz->activeRevision && $quiz->password_hash, 404);
        $password = $request->validate(['password' => ['required', 'string', 'max:255']])['password'];
        if (! Hash::check($password, $quiz->password_hash)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        $request->session()->put('quiz_unlocked.'.$quiz->id, now()->addMinutes((int) config('quiz.unlock_minutes', 480))->getTimestamp());

        return redirect()->route('quizzes.show', $quiz);
    }

    public function savePage(Request $request, Submission $submission, int $page, SaveQuizPage $save): RedirectResponse
    {
        $data = $request->validate(['answers' => ['nullable', 'array'], 'direction' => ['required', 'in:back,next']]);
        $save->handle($submission, $page, $data['answers'] ?? [], $data['direction'], $request);

        if ($submission->fresh()->status === SubmissionStatus::AwaitingContact) {
            return redirect()->route('quizzes.contact', ['quiz' => $submission->quiz, 'submission' => $submission]);
        }

        return redirect()->route('quizzes.show', $submission->quiz);
    }

    private function isUnlocked(Request $request, Quiz $quiz): bool
    {
        return (int) $request->session()->get('quiz_unlocked.'.$quiz->id, 0) >= now()->getTimestamp();
    }
}

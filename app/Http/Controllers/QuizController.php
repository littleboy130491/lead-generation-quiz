<?php

namespace App\Http\Controllers;

use App\Actions\Quizzes\SaveDraftPreviewPage;
use App\Actions\Submissions\SaveQuizPage;
use App\Actions\Submissions\StartOrResumeSubmission;
use App\Domain\Quiz\Opening\QuizOpening;
use App\Domain\Quiz\Pagination\VisibleQuizPages;
use App\Enums\QuizStatus;
use App\Enums\SubmissionStatus;
use App\Models\Quiz;
use App\Models\Submission;
use App\Services\CompletionHtmlSanitizer;
use App\Services\QuizDraftPreview;
use App\Services\SubmissionContext;
use App\Services\SubmissionEventRecorder;
use App\Settings\ApplicationSettings;
use App\Settings\BrandingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class QuizController extends Controller
{
    public function show(Request $request, Quiz $quiz, StartOrResumeSubmission $start, ApplicationSettings $settings, BrandingSettings $branding, CompletionHtmlSanitizer $html, QuizDraftPreview $draftPreview)
    {
        if ($quiz->status === QuizStatus::Published && $quiz->activeRevision) {
            return $this->showPublished($request, $quiz, $start, $settings, $branding, $html);
        }

        if ($quiz->status === QuizStatus::Draft && $draftPreview->canAccess($request, $quiz)) {
            return $this->renderDraftPreview($request, $quiz, $branding, $html, $draftPreview);
        }

        abort(404);
    }

    public function draftPreview(Request $request, Quiz $quiz, BrandingSettings $branding, CompletionHtmlSanitizer $html, QuizDraftPreview $draftPreview)
    {
        abort_unless($draftPreview->canAccess($request, $quiz), 404);

        return $this->renderDraftPreview($request, $quiz, $branding, $html, $draftPreview);
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

    public function dismissOpening(Request $request, Submission $submission): RedirectResponse
    {
        if ($submission->status !== SubmissionStatus::InProgress) {
            throw ValidationException::withMessages(['opening' => 'This questionnaire is no longer editable.']);
        }

        $definition = $submission->quizRevision->definition ?? [];
        if (! QuizOpening::isGated($definition)) {
            throw ValidationException::withMessages(['opening' => 'This quiz does not have a startable opening page.']);
        }

        $metadata = $submission->metadata ?? [];
        $metadata['opening_dismissed'] = true;
        Submission::query()->whereKey($submission->id)->update([
            'metadata' => $metadata,
            'current_page' => 0,
            'last_activity_at' => now(),
        ]);
        $submission->refresh();
        app(SubmissionEventRecorder::class)->touch($submission, app(SubmissionContext::class)->capture($request));
        $submission->refresh();
        app(SubmissionEventRecorder::class)->record($submission, 'opening_dismissed');

        return redirect()->route('quizzes.show', $submission->quiz);
    }

    public function dismissDraftOpening(Request $request, Quiz $quiz, QuizDraftPreview $draftPreview): RedirectResponse
    {
        abort_unless($draftPreview->canAccess($request, $quiz), 404);
        $definition = $draftPreview->definition($quiz);
        if (! QuizOpening::isGated($definition)) {
            throw ValidationException::withMessages(['opening' => 'This quiz does not have a startable opening page.']);
        }

        $state = $draftPreview->state($request, $quiz);
        $state->metadata['opening_dismissed'] = true;
        $state->current_page = 0;
        $draftPreview->put($request, $quiz, $state);

        return redirect()->route('quizzes.draft-preview.show', $quiz);
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

    public function saveDraftPage(Request $request, Quiz $quiz, int $page, SaveDraftPreviewPage $save, QuizDraftPreview $draftPreview): RedirectResponse
    {
        abort_unless($draftPreview->canAccess($request, $quiz), 404);
        $data = $request->validate(['answers' => ['nullable', 'array'], 'direction' => ['required', 'in:back,next']]);
        $outcome = $save->handle($quiz, $page, $data['answers'] ?? [], $data['direction'], $request);

        if ($outcome === 'finished') {
            return redirect()->route('quizzes.draft-preview.complete', $quiz);
        }

        return redirect()->route('quizzes.draft-preview.show', $quiz);
    }

    public function draftComplete(Request $request, Quiz $quiz, BrandingSettings $branding, QuizDraftPreview $draftPreview)
    {
        abort_unless($draftPreview->canAccess($request, $quiz), 404);

        return view('quiz.draft-complete', [
            'quiz' => $quiz,
            'branding' => $branding,
            'isDraftPreview' => true,
        ]);
    }

    private function showPublished(Request $request, Quiz $quiz, StartOrResumeSubmission $start, ApplicationSettings $settings, BrandingSettings $branding, CompletionHtmlSanitizer $html)
    {
        if ($quiz->password_hash && ! $this->isUnlocked($request, $quiz)) {
            return view('quiz.unlock', [
                'quiz' => $quiz,
                'branding' => $branding,
                'isDraftPreview' => false,
            ]);
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

        $definition = $submission->quizRevision->definition ?? [];
        $opening = QuizOpening::fromDefinition($definition);
        $openingPending = QuizOpening::isPending($definition, $submission);
        $openingHtml = $opening ? $html->sanitize($opening['html']) : '';
        $showInlineOpening = QuizOpening::isInlineOnFirstPage($definition, $submission);

        $pages = app(VisibleQuizPages::class)->forSubmission($submission, $submission->answers_snapshot ?? []);
        if (! isset($pages[$submission->current_page])) {
            $submission->update(['current_page' => 0]);
            $submission->refresh();
        }
        $page = $openingPending ? [] : ($pages[$submission->current_page] ?? []);
        $response = response()->view('quiz.show', [
            'quiz' => $quiz,
            'submission' => $submission,
            'pages' => $pages,
            'page' => $page,
            'branding' => $branding,
            'opening' => $opening,
            'openingHtml' => $openingHtml,
            'openingPending' => $openingPending,
            'showInlineOpening' => $showInlineOpening,
            'isDraftPreview' => false,
        ]);
        if ($token) {
            $response->cookie('quiz_resume_'.$quiz->id, $token, 60 * 24 * $settings->operation('resume_days'), null, null, app()->environment('production'), true, false, 'lax');
        }

        return $response;
    }

    private function renderDraftPreview(Request $request, Quiz $quiz, BrandingSettings $branding, CompletionHtmlSanitizer $html, QuizDraftPreview $draftPreview)
    {
        $definition = $draftPreview->definition($quiz);
        $state = $draftPreview->state($request, $quiz);
        $opening = QuizOpening::fromDefinition($definition);
        $openingPending = QuizOpening::isPending($definition, $state);
        $openingHtml = $opening ? $html->sanitize($opening['html']) : '';
        $showInlineOpening = QuizOpening::isInlineOnFirstPage($definition, $state);

        $pages = app(VisibleQuizPages::class)->forDefinition($definition, $state->answers_snapshot);
        if ($pages !== [] && ! isset($pages[$state->current_page])) {
            $state->current_page = 0;
            $draftPreview->put($request, $quiz, $state);
        }
        $page = $openingPending ? [] : ($pages[$state->current_page] ?? []);

        if (! $openingPending && $pages === []) {
            return view('quiz.draft-empty', [
                'quiz' => $quiz,
                'branding' => $branding,
                'isDraftPreview' => true,
            ]);
        }

        return view('quiz.show', [
            'quiz' => $quiz,
            'submission' => $state,
            'pages' => $pages,
            'page' => $page,
            'branding' => $branding,
            'opening' => $opening,
            'openingHtml' => $openingHtml,
            'openingPending' => $openingPending,
            'showInlineOpening' => $showInlineOpening,
            'isDraftPreview' => true,
        ]);
    }

    private function isUnlocked(Request $request, Quiz $quiz): bool
    {
        return (int) $request->session()->get('quiz_unlocked.'.$quiz->id, 0) >= now()->getTimestamp();
    }
}

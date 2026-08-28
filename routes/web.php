<?php

use App\Http\Controllers\AdminQuizHistoryController;
use App\Http\Controllers\QuizContactController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\QuizProgressController;
use App\Http\Controllers\Webhooks\MailgunWebhookController;
use Illuminate\Support\Facades\Route;

// Route::get('/', fn () => view('welcome'))->name('home');
Route::post('/webhooks/mailgun', MailgunWebhookController::class)->name('webhooks.mailgun');
Route::middleware('auth')->prefix('admin/quizzes/{quiz}')->group(function (): void {
    Route::get('/preview', [AdminQuizHistoryController::class, 'preview'])->name('admin.quizzes.preview');
    Route::get('/history', [AdminQuizHistoryController::class, 'history'])->name('admin.quizzes.history');
});

Route::post('/{quiz:slug}/unlock', [QuizController::class, 'unlock'])
    ->middleware('throttle:quiz-unlock')
    ->where('quiz', '^(?!admin$|webhooks$|up$|assets$|build$|storage$|livewire$|sanctum$).+')
    ->name('quizzes.unlock');
Route::get('/{quiz:slug}/draft-preview', [QuizController::class, 'draftPreview'])
    ->middleware(['auth', 'throttle:quiz-start'])
    ->where('quiz', '^(?!admin$|webhooks$|up$|assets$|build$|storage$|livewire$|sanctum$).+')
    ->name('quizzes.draft-preview.show');
Route::post('/{quiz:slug}/draft-preview/opening', [QuizController::class, 'dismissDraftOpening'])
    ->middleware(['auth', 'throttle:quiz-progress'])
    ->where('quiz', '^(?!admin$|webhooks$|up$|assets$|build$|storage$|livewire$|sanctum$).+')
    ->name('quizzes.draft-preview.dismiss-opening');
Route::post('/{quiz:slug}/draft-preview/pages/{page}', [QuizController::class, 'saveDraftPage'])
    ->middleware(['auth', 'throttle:quiz-progress'])
    ->where('quiz', '^(?!admin$|webhooks$|up$|assets$|build$|storage$|livewire$|sanctum$).+')
    ->whereNumber('page')
    ->name('quizzes.draft-preview.save-page');
Route::get('/{quiz:slug}/draft-preview/complete', [QuizController::class, 'draftComplete'])
    ->middleware('auth')
    ->where('quiz', '^(?!admin$|webhooks$|up$|assets$|build$|storage$|livewire$|sanctum$).+')
    ->name('quizzes.draft-preview.complete');
Route::get('/{quiz:slug}/contact/{submission}', [QuizProgressController::class, 'contact'])
    ->middleware('quiz.submission')
    ->name('quizzes.contact');
Route::get('/{quiz:slug}/complete/{submission}', [QuizContactController::class, 'complete'])
    ->middleware('quiz.submission')
    ->name('quizzes.complete');
Route::post('/submissions/{submission}/pages/{page}', [QuizController::class, 'savePage'])
    ->middleware(['quiz.submission', 'throttle:quiz-progress'])
    ->whereNumber('page')
    ->name('submissions.save-page');
Route::post('/submissions/{submission}/opening', [QuizController::class, 'dismissOpening'])
    ->middleware(['quiz.submission', 'throttle:quiz-progress'])
    ->name('submissions.dismiss-opening');
Route::post('/submissions/{submission}/contact', [QuizContactController::class, 'store'])
    ->middleware(['quiz.submission', 'throttle:quiz-contact'])
    ->name('submissions.finalize');
Route::get('/{quiz:slug}', [QuizController::class, 'show'])
    ->middleware('throttle:quiz-start')
    ->where('quiz', '^(?!admin$|webhooks$|up$|assets$|build$|storage$|livewire$|sanctum$).+')
    ->name('quizzes.show');

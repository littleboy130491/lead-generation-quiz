<?php

use App\Http\Controllers\AdminQuizHistoryController;
use App\Http\Controllers\QuizContactController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\QuizProgressController;
use App\Http\Controllers\Webhooks\MailgunWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'))->name('home');
Route::post('/webhooks/mailgun', MailgunWebhookController::class)->name('webhooks.mailgun');
Route::middleware('auth')->prefix('admin/quizzes/{quiz}')->group(function (): void {
    Route::get('/preview', [AdminQuizHistoryController::class, 'preview'])->name('admin.quizzes.preview');
    Route::get('/history', [AdminQuizHistoryController::class, 'history'])->name('admin.quizzes.history');
});

Route::post('/{quiz:slug}/unlock', [QuizController::class, 'unlock'])
    ->middleware('throttle:quiz-unlock')
    ->where('quiz', '^(?!admin$|webhooks$|up$|assets$|build$|storage$|livewire$|sanctum$).+')
    ->name('quizzes.unlock');
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

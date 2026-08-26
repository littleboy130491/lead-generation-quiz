<?php

use App\Http\Controllers\Api\QuizGenerationController;
use App\Http\Controllers\Api\UserProvisioningController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['quiz.api-token', 'throttle:20,1'])->group(function (): void {
    Route::post('/quizzes/generate', QuizGenerationController::class)->name('api.v1.quizzes.generate');
    Route::post('/users', UserProvisioningController::class)->name('api.v1.users.store');
});

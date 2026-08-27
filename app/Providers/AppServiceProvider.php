<?php

namespace App\Providers;

use App\Ai\Contracts\QuizAnalysisGenerator;
use App\Ai\Contracts\QuizDefinitionGenerator;
use App\Ai\Debug\AiDebugLogger;
use App\Ai\Discovery\LaravelQuizDiscoveryInterviewer;
use App\Ai\Discovery\QuizDiscoveryInterviewer;
use App\Ai\LaravelAi\LaravelQuizAnalysisGenerator;
use App\Ai\LaravelAi\LaravelQuizDefinitionGenerator;
use App\Mail\Contracts\ReportDeliveryTransport;
use App\Mail\LaravelReportDeliveryTransport;
use App\Security\Turnstile\CloudflareTurnstileVerifier;
use App\Security\Turnstile\NullTurnstileVerifier;
use App\Security\Turnstile\TurnstileVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QuizAnalysisGenerator::class, LaravelQuizAnalysisGenerator::class);
        $this->app->bind(QuizDefinitionGenerator::class, LaravelQuizDefinitionGenerator::class);
        $this->app->bind(QuizDiscoveryInterviewer::class, LaravelQuizDiscoveryInterviewer::class);
        $this->app->bind(ReportDeliveryTransport::class, LaravelReportDeliveryTransport::class);
        $this->app->bind(TurnstileVerifier::class, function () {
            return config('services.turnstile.secret_key')
                ? new CloudflareTurnstileVerifier
                : new NullTurnstileVerifier;
        });
    }

    public function boot(): void
    {
        RateLimiter::for('quiz-start', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('quiz-unlock', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('quiz-progress', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('quiz-questionnaire', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('quiz-contact', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        if (AiDebugLogger::enabled()) {
            Event::listen(StartingStep::class, [AiDebugLogger::class, 'handleStartingStep']);
            Event::listen(StepCompleted::class, [AiDebugLogger::class, 'handleStepCompleted']);
            Event::listen(StepFailed::class, [AiDebugLogger::class, 'handleStepFailed']);
            Event::listen(ProviderFailedOver::class, [AiDebugLogger::class, 'handleProviderFailedOver']);
        }
    }
}

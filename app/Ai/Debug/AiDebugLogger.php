<?php

namespace App\Ai\Debug;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Messages\Message;

/**
 * Opt-in diagnostics for synchronous provider calls.
 *
 * Slow or failing generations are otherwise only visible as a wall-clock delay,
 * so each step records its provider, model, duration, token usage, and finish
 * reason. Prompt and response bodies require the separate content flag because
 * analysis calls carry respondent answers. Credentials are never recorded.
 */
class AiDebugLogger
{
    public static function enabled(): bool
    {
        return (bool) config('ai_debug.enabled');
    }

    public function handleStartingStep(StartingStep $event): void
    {
        $context = [
            'invocation' => $event->invocationId,
            'step' => $event->stepNumber,
            'model' => $event->model,
            'messages' => count($event->messages),
        ];

        if ($this->logsContent()) {
            $context['prompt'] = array_map(fn (Message $message): array => [
                'role' => $message->role->value,
                'content' => $this->truncate((string) $message->content),
            ], $event->messages);
        }

        $this->log('AI step starting', $context);
    }

    public function handleStepCompleted(StepCompleted $event): void
    {
        $response = $event->response;

        $context = [
            'invocation' => $event->invocationId,
            'step' => $event->stepNumber,
            'model' => $event->model,
            'responding_model' => $response->meta->model,
            'duration_ms' => round($event->time),
            'finish_reason' => $response->finishReason->value,
            'usage' => $response->usage->toArray(),
            'structured' => $response->structured !== null,
            'text_characters' => mb_strlen($response->text),
        ];

        if ($this->logsContent()) {
            $context['response'] = $this->truncate($response->structured !== null
                ? (json_encode($response->structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
                : $response->text);
        }

        $this->log('AI step completed', $context);
    }

    public function handleStepFailed(StepFailed $event): void
    {
        $this->log('AI step failed', [
            'invocation' => $event->invocationId,
            'step' => $event->stepNumber,
            'model' => $event->model,
            'duration_ms' => round($event->time),
            'exception' => $event->exception::class,
            'message' => $this->truncate($event->exception->getMessage()),
        ]);
    }

    public function handleProviderFailedOver(ProviderFailedOver $event): void
    {
        $this->log('AI provider failed over', [
            'model' => $event->model,
            'exception' => $event->exception::class,
            'message' => $this->truncate($event->exception->getMessage()),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function log(string $message, array $context): void
    {
        Log::channel((string) config('ai_debug.channel', 'ai'))->debug($message, $context);
    }

    private function logsContent(): bool
    {
        return (bool) config('ai_debug.log_content');
    }

    private function truncate(string $value): string
    {
        return str($value)->limit((int) config('ai_debug.max_content_characters', 4000))->toString();
    }
}

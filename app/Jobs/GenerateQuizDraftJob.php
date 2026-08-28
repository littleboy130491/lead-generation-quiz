<?php

namespace App\Jobs;

use App\Actions\Quizzes\GenerateQuizDraft;
use App\Ai\GenerationException;
use App\Enums\QuizDiscoveryStatus;
use App\Models\QuizDiscoverySession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GenerateQuizDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $sessionId,
        public string $executionToken = '',
    ) {
        $this->executionToken = $executionToken !== '' ? $executionToken : (string) Str::uuid();
        $this->onQueue('ai');
    }

    public function handle(GenerateQuizDraft $generateDraft): void
    {
        $claimed = QuizDiscoverySession::query()
            ->whereKey($this->sessionId)
            ->where('status', QuizDiscoveryStatus::Generating)
            ->where('generation_token', $this->executionToken)
            ->whereNull('generation_started_at')
            ->update([
                'generation_started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $session = QuizDiscoverySession::query()->with('quiz')->findOrFail($this->sessionId);

        try {
            if ($session->quiz === null) {
                throw new \RuntimeException('The quiz for this interview could not be found.');
            }

            $activeSession = null;
            $generateDraft->handle(
                $session->quiz,
                $session->brief ?? [],
                beforePersist: function () use (&$activeSession): bool {
                    $claimed = QuizDiscoverySession::query()
                        ->whereKey($this->sessionId)
                        ->where('status', QuizDiscoveryStatus::Generating)
                        ->where('generation_token', $this->executionToken)
                        ->update(['updated_at' => now()]);

                    if ($claimed !== 1) {
                        return false;
                    }

                    $activeSession = QuizDiscoverySession::query()->findOrFail($this->sessionId);

                    return true;
                },
                afterPersist: function () use (&$activeSession): void {
                    if ($activeSession === null) {
                        throw new \LogicException('The quiz generation session was not claimed for completion.');
                    }

                    $activeSession->update([
                        'status' => QuizDiscoveryStatus::Generated,
                        'generation_finished_at' => now(),
                        'generation_error_code' => null,
                        'generation_error_message' => null,
                    ]);
                    $activeSession->messages()->create([
                        'role' => 'assistant',
                        'content' => 'Your quiz draft is ready. Review it and make any changes before publishing.',
                        'brief_snapshot' => $activeSession->brief,
                    ]);
                },
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->failSession($exception);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->failSession($exception ?? new \RuntimeException('The generation worker stopped unexpectedly.'));
    }

    private function failSession(\Throwable $exception): void
    {
        [$code, $message] = $this->normalizedFailure($exception);

        DB::transaction(function () use ($code, $message): void {
            $session = QuizDiscoverySession::query()
                ->whereKey($this->sessionId)
                ->where('status', QuizDiscoveryStatus::Generating)
                ->where('generation_token', $this->executionToken)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return;
            }

            $session->update([
                'status' => QuizDiscoveryStatus::Failed,
                'generation_finished_at' => now(),
                'generation_error_code' => $code,
                'generation_error_message' => $message,
            ]);
            $session->messages()->create([
                'role' => 'assistant',
                'content' => 'Quiz draft generation could not be completed. '.$message.' You can try again.',
                'brief_snapshot' => $session->brief,
            ]);
        });
    }

    /** @return array{string, string} */
    private function normalizedFailure(\Throwable $exception): array
    {
        if ($exception instanceof ValidationException) {
            return [
                'quiz_contract_invalid',
                str(implode(' ', $exception->validator->errors()->all()))->limit(500)->toString(),
            ];
        }

        if ($exception instanceof GenerationException) {
            $attempts = collect($exception->attempts)
                ->map(fn (array $attempt): string => trim(($attempt['provider'] ?? 'provider').' '.($attempt['model'] ?? '').': '.($attempt['message'] ?? 'failed')))
                ->implode(' | ');

            return [
                $exception->codeName,
                str($attempts !== '' ? $attempts : $exception->getMessage())->limit(500)->toString(),
            ];
        }

        return [
            'quiz_generation_failed',
            str($exception->getMessage() !== '' ? $exception->getMessage() : 'Generation failed unexpectedly.')->limit(500)->toString(),
        ];
    }
}

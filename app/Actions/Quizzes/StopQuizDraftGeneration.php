<?php

namespace App\Actions\Quizzes;

use App\Enums\QuizDiscoveryStatus;
use App\Models\QuizDiscoverySession;
use Illuminate\Support\Facades\DB;

class StopQuizDraftGeneration
{
    public const MESSAGE = 'Quiz draft generation was stopped.';

    public function handle(int $sessionId, int $userId): ?QuizDiscoverySession
    {
        return DB::transaction(function () use ($sessionId, $userId): ?QuizDiscoverySession {
            $stopped = QuizDiscoverySession::query()
                ->whereKey($sessionId)
                ->where('user_id', $userId)
                ->where('status', QuizDiscoveryStatus::Generating)
                ->update([
                    'status' => QuizDiscoveryStatus::Cancelled,
                    'generation_finished_at' => now(),
                    'generation_error_code' => 'cancelled_by_admin',
                    'generation_error_message' => 'Stopped by the administrator.',
                    'updated_at' => now(),
                ]);

            if ($stopped !== 1) {
                return null;
            }

            $session = QuizDiscoverySession::query()->findOrFail($sessionId);
            $session->messages()->create([
                'role' => 'assistant',
                'content' => self::MESSAGE,
                'brief_snapshot' => $session->brief,
            ]);

            return $session->fresh(['messages']);
        });
    }
}

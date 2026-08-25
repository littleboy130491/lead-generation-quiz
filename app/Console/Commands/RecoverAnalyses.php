<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Jobs\GenerateAnalysisJob;
use App\Models\Analysis;
use App\Settings\ApplicationSettings;
use Illuminate\Console\Command;

class RecoverAnalyses extends Command
{
    protected $signature = 'analyses:recover-stale';

    protected $description = 'Requeue stale processing and eligible failed analyses idempotently';

    public function handle(): int
    {
        $now = now();
        $staleBefore = $now->copy()->subMinutes(config('quiz.analysis_recovery.stale_after_minutes'));
        $retryBefore = $now->copy()->subMinutes(config('quiz.analysis_recovery.retry_backoff_minutes'));
        $maxAttempts = app(ApplicationSettings::class)->operation('retry_attempts');

        Analysis::query()->where(function ($query) use ($staleBefore, $retryBefore, $maxAttempts): void {
            $query->where(function ($query) use ($staleBefore): void {
                $query->where('status', AnalysisStatus::Processing)
                    ->where(function ($query) use ($staleBefore): void {
                        $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<=', $staleBefore);
                    });
            })->orWhere(function ($query) use ($retryBefore, $maxAttempts): void {
                $query->where('status', AnalysisStatus::Failed)
                    ->where('attempt_count', '<', $maxAttempts)
                    ->where(function ($query) use ($retryBefore): void {
                        $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<=', $retryBefore);
                    });
            });
        })->select('id', 'status')->eachById(function (Analysis $analysis) use ($now, $staleBefore, $retryBefore, $maxAttempts): void {
            $eligible = Analysis::query()->whereKey($analysis->id)
                ->where(function ($query) use ($staleBefore, $retryBefore, $maxAttempts): void {
                    $query->where(function ($query) use ($staleBefore): void {
                        $query->where('status', AnalysisStatus::Processing)->where(function ($query) use ($staleBefore): void {
                            $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<=', $staleBefore);
                        });
                    })->orWhere(function ($query) use ($retryBefore, $maxAttempts): void {
                        $query->where('status', AnalysisStatus::Failed)->where('attempt_count', '<', $maxAttempts)
                            ->where(function ($query) use ($retryBefore): void {
                                $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<=', $retryBefore);
                            });
                    });
                })->update([
                    'status' => AnalysisStatus::Queued,
                    'queued_at' => $now,
                    'heartbeat_at' => null,
                    'execution_lease' => null,
                    'lease_expires_at' => null,
                    'execution_generation' => \DB::raw('execution_generation + 1'),
                    'recovery_count' => \DB::raw('recovery_count + 1'),
                    'updated_at' => $now,
                ]);

            if ($eligible === 1) {
                GenerateAnalysisJob::dispatch($analysis->id);
            }
        });

        return self::SUCCESS;
    }
}

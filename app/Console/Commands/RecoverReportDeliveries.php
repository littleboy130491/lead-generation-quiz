<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Jobs\SendReportDeliveryJob;
use App\Models\ReportDelivery;
use App\Settings\ApplicationSettings;
use Illuminate\Console\Command;

class RecoverReportDeliveries extends Command
{
    protected $signature = 'reports:recover-stale';

    protected $description = 'Requeue stale sending and eligible failed report deliveries idempotently';

    public function handle(): int
    {
        $now = now();
        $staleBefore = $now->copy()->subMinutes(config('quiz.delivery_recovery.stale_after_minutes'));
        $retryBefore = $now->copy()->subMinutes(config('quiz.delivery_recovery.retry_backoff_minutes'));
        $maxAttempts = app(ApplicationSettings::class)->operation('retry_attempts');

        ReportDelivery::query()->where(function ($query) use ($staleBefore, $retryBefore, $maxAttempts): void {
            $query->where(function ($query) use ($staleBefore): void {
                $query->where('status', DeliveryStatus::Sending)->where('sent_at', '<=', $staleBefore);
            })->orWhere(function ($query) use ($retryBefore, $maxAttempts): void {
                $query->where('status', DeliveryStatus::Failed)->where('attempt_count', '<', $maxAttempts)
                    ->where('failed_at', '<=', $retryBefore);
            });
        })->select('id')->eachById(function (ReportDelivery $delivery) use ($now, $staleBefore, $retryBefore, $maxAttempts): void {
            $eligible = ReportDelivery::query()->whereKey($delivery->id)
                ->where(function ($query) use ($staleBefore, $retryBefore, $maxAttempts): void {
                    $query->where(function ($query) use ($staleBefore): void {
                        $query->where('status', DeliveryStatus::Sending)->where('sent_at', '<=', $staleBefore);
                    })->orWhere(function ($query) use ($retryBefore, $maxAttempts): void {
                        $query->where('status', DeliveryStatus::Failed)->where('attempt_count', '<', $maxAttempts)
                            ->where('failed_at', '<=', $retryBefore);
                    });
                })->update([
                    'status' => DeliveryStatus::Queued,
                    'queued_at' => $now,
                    'execution_lease' => null,
                    'lease_expires_at' => null,
                    'execution_generation' => \DB::raw('execution_generation + 1'),
                    'recovery_count' => \DB::raw('recovery_count + 1'),
                    'updated_at' => $now,
                ]);

            if ($eligible === 1) {
                SendReportDeliveryJob::dispatch($delivery->id);
            }
        });

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Mail\Contracts\ReportDeliveryTransport;
use App\Models\ReportDelivery;
use App\Services\SubmissionEventRecorder;
use App\Settings\ApplicationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendReportDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $deliveryId, public ?string $lease = null) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addSeconds(app(ApplicationSettings::class)->operation('timeout_seconds'));
    }

    public function handle(ReportDeliveryTransport $transport, ?SubmissionEventRecorder $events = null): void
    {
        $events ??= app(SubmissionEventRecorder::class);
        $lease = $this->lease ?? (string) Str::uuid();
        $now = now();
        if ($this->lease === null) {
            $claimed = ReportDelivery::query()->whereKey($this->deliveryId)->where('status', DeliveryStatus::Queued)->update([
                'status' => DeliveryStatus::Sending, 'sent_at' => $now, 'attempt_count' => DB::raw('attempt_count + 1'),
                'execution_generation' => DB::raw('execution_generation + 1'), 'execution_lease' => $lease,
                'lease_expires_at' => $now->copy()->addSeconds(app(ApplicationSettings::class)->operation('timeout_seconds')), 'updated_at' => $now,
            ]);
        } else {
            $claimed = ReportDelivery::query()->whereKey($this->deliveryId)->where('status', DeliveryStatus::Sending)->where('execution_lease', $lease)->update(['updated_at' => $now]);
        }
        if ($claimed !== 1) {
            return;
        }
        $delivery = ReportDelivery::findOrFail($this->deliveryId);
        $fence = $delivery->execution_generation;
        if (! $this->renew($lease, $fence)) {
            return;
        }
        try {
            $providerMessageId = $transport->send($delivery);
            if ($this->updateOwned($lease, $fence, ['status' => DeliveryStatus::Accepted, 'provider' => config('mail.default'), 'provider_message_id' => $providerMessageId, 'sent_at' => now(), 'lease_expires_at' => null, 'error_code' => null, 'error_message' => null])) {
                $events->record($delivery->submission, 'delivery_accepted', ['delivery_id' => $delivery->id]);
            }
        } catch (\Throwable $e) {
            if ($this->updateOwned($lease, $fence, ['status' => DeliveryStatus::Failed, 'failed_at' => now(), 'lease_expires_at' => null, 'error_code' => 'mail_send_failed', 'error_message' => str($e->getMessage())->limit(500)->toString()])) {
                $events->record($delivery->submission, 'delivery_failed', ['delivery_id' => $delivery->id]);
            }
        }
    }

    private function renew(string $lease, int $fence): bool
    {
        return ReportDelivery::query()->whereKey($this->deliveryId)->where('status', DeliveryStatus::Sending)->where('execution_lease', $lease)->where('execution_generation', $fence)->update(['lease_expires_at' => now()->addSeconds(app(ApplicationSettings::class)->operation('timeout_seconds')), 'updated_at' => now()]) === 1;
    }

    private function updateOwned(string $lease, int $fence, array $attributes): bool
    {
        $attributes['updated_at'] = now();

        return ReportDelivery::query()->whereKey($this->deliveryId)->where('status', DeliveryStatus::Sending)->where('execution_lease', $lease)->where('execution_generation', $fence)->update($attributes) === 1;
    }
}

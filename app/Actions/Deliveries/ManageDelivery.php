<?php

namespace App\Actions\Deliveries;

use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTrigger;
use App\Models\ReportDelivery;
use App\Services\SubmissionEventRecorder;

class ManageDelivery
{
    public function __construct(private RequestReportDelivery $requests, private SubmissionEventRecorder $events) {}

    public function retry(ReportDelivery $delivery, ?int $actorId = null): ReportDelivery
    {
        return $this->requests->handle($delivery->analysis, DeliveryTrigger::Manual, $actorId);
    }

    public function cancel(ReportDelivery $delivery, ?int $actorId = null): void
    {
        if ($delivery->status === DeliveryStatus::Queued) {
            $delivery->update(['status' => DeliveryStatus::Failed, 'failed_at' => now(), 'error_code' => 'cancelled_by_admin', 'error_message' => 'Cancelled by administrator.']);
            $this->events->record($delivery->submission, 'delivery_cancelled', ['delivery_id' => $delivery->id, 'actor_id' => $actorId]);
        }
    }
}

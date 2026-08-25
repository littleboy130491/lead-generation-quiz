<?php

namespace App\Actions\Deliveries;

use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTrigger;
use App\Jobs\SendReportDeliveryJob;
use App\Mail\ReportRenderer;
use App\Models\Analysis;
use App\Models\ReportDelivery;
use App\Services\SubmissionEventRecorder;
use Illuminate\Support\Facades\DB;

class RequestReportDelivery
{
    public function __construct(private ReportRenderer $renderer, private SubmissionEventRecorder $events) {}

    public function handle(Analysis $analysis, DeliveryTrigger $trigger = DeliveryTrigger::Automatic, ?int $requestedBy = null): ReportDelivery
    {
        return DB::transaction(function () use ($analysis, $trigger, $requestedBy) {
            $analysis->loadMissing('submission');
            $delivery = $trigger === DeliveryTrigger::Automatic
                ? ReportDelivery::firstOrCreate(['analysis_id' => $analysis->id, 'automatic_key' => 'initial'], $this->attributes($analysis, $trigger, $requestedBy))
                : ReportDelivery::create($this->attributes($analysis, $trigger, $requestedBy));
            if ($delivery->wasRecentlyCreated) {
                $this->events->record($analysis->submission, 'delivery_requested', ['delivery_id' => $delivery->id, 'trigger' => $trigger->value]);
                SendReportDeliveryJob::dispatch($delivery->id)->afterCommit();
            }

            return $delivery;
        });
    }

    private function attributes(Analysis $analysis, DeliveryTrigger $trigger, ?int $requestedBy): array
    {
        $rendered = $this->renderer->render($analysis);

        return ['analysis_id' => $analysis->id, 'submission_id' => $analysis->submission_id, 'recipient_email' => $analysis->submission->email, 'status' => DeliveryStatus::Queued, 'trigger' => $trigger, 'sent_manually' => $trigger !== DeliveryTrigger::Automatic, 'requested_by' => $requestedBy, 'template_identifier' => 'report-v1', 'template_version' => '1', 'subject_snapshot' => $rendered['subject'], 'html_snapshot' => $rendered['html'], 'text_snapshot' => $rendered['text'], 'queued_at' => now()];
    }
}

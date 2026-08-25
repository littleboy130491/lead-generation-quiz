<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Jobs\SendReportDeliveryJob;
use App\Models\ReportDelivery;
use Illuminate\Console\Command;

class DispatchUnsentReports extends Command
{
    protected $signature = 'reports:dispatch-unsent';

    protected $description = 'Dispatch queued report deliveries idempotently';

    public function handle(): int
    {
        ReportDelivery::where('status', DeliveryStatus::Queued)->each(fn (ReportDelivery $delivery) => SendReportDeliveryJob::dispatch($delivery->id));

        return self::SUCCESS;
    }
}

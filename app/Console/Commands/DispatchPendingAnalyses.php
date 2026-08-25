<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Jobs\GenerateAnalysisJob;
use App\Models\Analysis;
use Illuminate\Console\Command;

class DispatchPendingAnalyses extends Command
{
    protected $signature = 'analyses:dispatch-pending';

    protected $description = 'Dispatch queued analyses idempotently';

    public function handle(): int
    {
        Analysis::where('status', AnalysisStatus::Queued)->each(fn ($a) => GenerateAnalysisJob::dispatch($a->id));

        return self::SUCCESS;
    }
}

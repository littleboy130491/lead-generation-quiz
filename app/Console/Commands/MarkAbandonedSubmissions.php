<?php

namespace App\Console\Commands;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Settings\ApplicationSettings;
use Illuminate\Console\Command;

class MarkAbandonedSubmissions extends Command
{
    protected $signature = 'submissions:mark-abandoned';

    protected $description = 'Mark expired incomplete submissions abandoned';

    public function handle(): int
    {
        Submission::where('status', SubmissionStatus::InProgress)->where('expires_at', '<', now())->update(['status' => SubmissionStatus::Abandoned]);
        Submission::where('status', SubmissionStatus::Abandoned)
            ->where('updated_at', '<', now()->subDays(app(ApplicationSettings::class)->operation('retention_days')))
            ->update(['resume_token_hash' => null]);

        return self::SUCCESS;
    }
}

<?php

namespace App\Actions\Submissions;

use App\Mail\SubmissionCompletedAdminMail;
use App\Models\Submission;
use App\Settings\ApplicationSettings;
use Illuminate\Support\Facades\Mail;

class NotifyAdminsOfCompletedSubmission
{
    public function __construct(private ApplicationSettings $settings) {}

    public function handle(Submission $submission): void
    {
        $emails = $this->settings->get('notifications')['submission_emails'] ?? [];
        if (! is_array($emails) || $emails === []) {
            return;
        }

        $submission->loadMissing('quiz');

        foreach ($emails as $email) {
            if (! is_string($email) || $email === '') {
                continue;
            }

            Mail::to($email)->queue(new SubmissionCompletedAdminMail($submission));
        }
    }
}

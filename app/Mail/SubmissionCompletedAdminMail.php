<?php

namespace App\Mail;

use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubmissionCompletedAdminMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Submission $submission) {}

    public function envelope(): Envelope
    {
        $quizName = (string) ($this->submission->quiz?->name ?? 'Quiz');

        return new Envelope(
            subject: 'New submission: '.$quizName,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.submissions.completed-html',
            text: 'mail.submissions.completed-text',
            with: [
                'quizName' => (string) ($this->submission->quiz?->name ?? 'Quiz'),
                'leadEmail' => (string) ($this->submission->email ?? ''),
                'leadName' => (string) ($this->submission->name ?? ''),
                'leadCompany' => (string) ($this->submission->company ?? ''),
                'leadPhone' => (string) ($this->submission->phone ?? ''),
                'publicId' => (string) $this->submission->public_id,
                'completedAt' => $this->submission->completed_at?->toDateTimeString() ?? '',
                'adminUrl' => url('/admin/submissions/'.$this->submission->id.'/edit'),
            ],
        );
    }
}

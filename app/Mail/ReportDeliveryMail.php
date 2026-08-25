<?php

namespace App\Mail;

use App\Models\ReportDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

class ReportDeliveryMail extends Mailable
{
    use Queueable;

    public function __construct(public ReportDelivery $delivery) {}

    public function build(): static
    {
        return $this->subject($this->delivery->subject_snapshot)->html($this->delivery->html_snapshot)->text('mail.reports.delivery-text', ['text' => $this->delivery->text_snapshot]);
    }
}

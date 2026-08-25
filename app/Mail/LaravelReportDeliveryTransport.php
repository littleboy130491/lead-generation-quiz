<?php

namespace App\Mail;

use App\Mail\Contracts\ReportDeliveryTransport;
use App\Models\ReportDelivery;
use Illuminate\Support\Facades\Mail;

class LaravelReportDeliveryTransport implements ReportDeliveryTransport
{
    public function send(ReportDelivery $delivery): ?string
    {
        $sent = Mail::to($delivery->recipient_email)->send(new ReportDeliveryMail($delivery));

        return $sent?->getMessageId();
    }
}

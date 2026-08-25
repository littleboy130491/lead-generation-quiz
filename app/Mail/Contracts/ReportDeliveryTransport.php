<?php

namespace App\Mail\Contracts;

use App\Models\ReportDelivery;

interface ReportDeliveryTransport
{
    /**
     * Send the delivery and return the provider's correlation identifier when available.
     */
    public function send(ReportDelivery $delivery): ?string;
}

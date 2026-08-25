<?php

namespace App\Enums;

enum DeliveryTrigger: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case BulkManual = 'bulk_manual';
}

<?php

namespace App\Enums;

enum AnalysisTrigger: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case BulkManual = 'bulk_manual';
    case Retry = 'retry';
}

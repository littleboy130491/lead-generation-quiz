<?php

namespace App\Enums;

enum AnalysisMode: string
{
    case Always = 'always';
    case Manual = 'manual';
    case EligibleOnly = 'eligible_only';
}

<?php

namespace App\Enums;

enum QuizDiscoveryStatus: string
{
    case Interviewing = 'interviewing';
    case Ready = 'ready';
    case Generating = 'generating';
    case Generated = 'generated';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
}

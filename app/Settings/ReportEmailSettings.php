<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class ReportEmailSettings extends Settings
{
    public string $from_name;

    public ?string $reply_to;

    public string $subject;

    public string $html;

    public string $text;

    public static function group(): string
    {
        return 'report_email';
    }
}

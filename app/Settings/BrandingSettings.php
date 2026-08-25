<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BrandingSettings extends Settings
{
    public string $site_name;

    public string $eyebrow;

    public ?string $logo_url;

    public string $primary_color;

    public string $secondary_color;

    public string $background_color;

    public string $text_color;

    public string $border_radius;

    public string $additional_css;

    public string $additional_js;

    public string $completion_html;

    public static function group(): string
    {
        return 'branding';
    }
}

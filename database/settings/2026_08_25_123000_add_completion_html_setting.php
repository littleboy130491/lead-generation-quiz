<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('branding.completion_html', '<h1>Thank you</h1><p>Your analysis is being prepared and will be sent to your email address.</p>');
    }
};

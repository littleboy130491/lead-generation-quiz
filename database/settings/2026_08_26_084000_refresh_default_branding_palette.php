<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Refresh stock defaults only when the original warm cream/amber palette is still in place.
        $this->migrator->update('branding.primary_color', fn (string $color) => $color === '#b45309' ? '#0f766e' : $color);
        $this->migrator->update('branding.secondary_color', fn (string $color) => $color === '#fff7ed' ? '#ccfbf1' : $color);
        $this->migrator->update('branding.background_color', fn (string $color) => $color === '#fffaf5' ? '#f0fdfa' : $color);
        $this->migrator->update('branding.text_color', fn (string $color) => $color === '#1c1917' ? '#042f2e' : $color);
    }
};

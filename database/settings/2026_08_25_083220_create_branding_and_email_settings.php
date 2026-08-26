<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('branding.site_name', 'Lead Generation Quiz');
        $this->migrator->add('branding.eyebrow', 'Business assessment');
        $this->migrator->add('branding.logo_url', null);
        $this->migrator->add('branding.primary_color', '#0f766e');
        $this->migrator->add('branding.secondary_color', '#ccfbf1');
        $this->migrator->add('branding.background_color', '#f0fdfa');
        $this->migrator->add('branding.text_color', '#042f2e');
        $this->migrator->add('branding.border_radius', '1rem');
        $this->migrator->add('branding.additional_css', '');
        $this->migrator->add('branding.additional_js', '');
        $this->migrator->add('report_email.from_name', 'Lead Generation Quiz');
        $this->migrator->add('report_email.reply_to', null);
        $this->migrator->add('report_email.subject', 'Your quiz report');
        $this->migrator->add('report_email.html', '<h1>{{report.executive_summary}}</h1>');
        $this->migrator->add('report_email.text', '{{report.executive_summary}}');
    }
};

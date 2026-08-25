<?php

namespace App\Mail;

use App\Ai\Data\ReportSchema;
use App\Models\Analysis;
use App\Settings\ApplicationSettings;

class ReportRenderer
{
    public function __construct(private ApplicationSettings $settings) {}

    /** @return array{subject:string,html:string,text:string} */
    public function render(Analysis $analysis): array
    {
        $report = ReportSchema::validate($analysis->structured_result ?? []);
        $template = $this->settings->get('report.email');
        $values = [
            '{{email}}' => (string) $analysis->submission->email,
            '{{report.executive_summary}}' => (string) $report['executive_summary'],
            '{{report.profile}}' => (string) $report['profile'],
            '{{report.disclaimer}}' => (string) $report['disclaimer'],
        ];
        $html = strtr((string) $template['html'], array_map(fn (string $value) => e($value), $values));
        $text = trim(strip_tags(strtr((string) $template['text'], $values)));

        return [
            'subject' => trim(preg_replace('/[\r\n]+/', ' ', strtr((string) $template['subject'], $values)) ?? 'Your quiz report'),
            'html' => $html,
            'text' => $text,
        ];
    }
}

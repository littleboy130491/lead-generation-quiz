<?php

namespace App\Settings;

use App\Models\ApplicationSetting;
use InvalidArgumentException;

class ApplicationSettings
{
    private const DEFAULTS = [
        'ai.quiz' => [],
        'ai.report' => [],
        'prompts' => ['quiz_version' => 'v1', 'quiz_template' => '', 'report_version' => 'v1', 'report_template' => ''],
        'report.email' => ['subject' => 'Your quiz report', 'html' => '<h1>{{report.executive_summary}}</h1>', 'text' => '{{report.executive_summary}}'],
        'design' => ['tokens' => [], 'additional_css' => ''],
        'spam' => ['turnstile_enabled' => false, 'analysis_mode' => 'always'],
        'operations' => ['resume_days' => 30, 'retention_days' => 90, 'retry_attempts' => 3, 'timeout_seconds' => 60],
    ];

    /** @return array<string, mixed> */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertAllowed($key);
        $stored = ApplicationSetting::query()->where('key', $key)->value('value');

        return is_array($stored) ? array_replace(self::DEFAULTS[$key], $stored) : (self::DEFAULTS[$key] ?? $default);
    }

    /** @param array<string, mixed>|list<array{provider:string,model:string}> $value */
    public function put(string $key, mixed $value): void
    {
        $this->assertAllowed($key);
        if (! is_array($value)) {
            throw new InvalidArgumentException('Application setting values must be structured arrays.');
        }
        self::validateStored($key, $value);
        ApplicationSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return collect(self::DEFAULTS)->mapWithKeys(fn (array $default, string $key) => [$key => $this->get($key, $default)])->all();
    }

    public function operation(string $key): int
    {
        return (int) $this->get('operations')[$key];
    }

    private function assertAllowed(string $key): void
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException('Only approved non-secret application settings may be stored.');
        }
    }

    /** @param array<mixed> $value */
    public static function validateStored(string $key, array $value): void
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException('Only approved non-secret application settings may be stored.');
        }
        if (in_array($key, ['ai.quiz', 'ai.report'], true)) {
            foreach ($value as $entry) {
                if (! is_array($entry) || array_keys($entry) !== ['provider', 'model'] || ! preg_match('/^[a-z0-9._-]{1,80}$/i', (string) $entry['provider']) || ! preg_match('/^[a-z0-9._:-]{1,120}$/i', (string) $entry['model'])) {
                    throw new InvalidArgumentException('Provider chains contain only safe provider and model pairs.');
                }
            }

            return;
        }

        $allowed = array_keys(self::DEFAULTS[$key]);
        if (array_diff(array_keys($value), $allowed)) {
            throw new InvalidArgumentException('Unsupported setting fields are not allowed.');
        }
        if ($key === 'prompts') {
            foreach (['quiz_version', 'report_version'] as $field) {
                if (isset($value[$field]) && ! preg_match('/^[a-z0-9._-]{1,60}$/i', (string) $value[$field])) {
                    throw new InvalidArgumentException('Prompt versions must be safe labels.');
                }
            }
            foreach (['quiz_template', 'report_template'] as $field) {
                if (isset($value[$field]) && (! is_string($value[$field]) || strlen($value[$field]) > 10000 || str_contains(strtolower($value[$field]), '<?'))) {
                    throw new InvalidArgumentException('Prompt templates must be bounded text.');
                }
            }

            return;
        }
        if ($key === 'report.email') {
            foreach (['subject', 'html', 'text'] as $field) {
                if (! isset($value[$field]) || ! is_string($value[$field]) || strlen($value[$field]) > 20000 || preg_match('/<\?(?:php|=)?|{!!|@php|<script|javascript:/i', $value[$field])) {
                    throw new InvalidArgumentException('Email templates must be safe bounded text with simple placeholders only.');
                }
            }
            if (preg_match_all('/{{\s*([^}]+)\s*}}/', implode("\n", $value), $matches) && array_diff($matches[1], ['email', 'report.executive_summary', 'report.profile', 'report.disclaimer'])) {
                throw new InvalidArgumentException('Email templates use only approved placeholders.');
            }

            return;
        }
        if ($key === 'design') {
            if (! is_array($value['tokens'] ?? null) || ! is_string($value['additional_css'] ?? '') || strlen($value['additional_css']) > 20000 || preg_match('/@import|url\s*\(|expression\s*\(|javascript:|<|>/i', $value['additional_css'])) {
                throw new InvalidArgumentException('Design CSS contains an unsafe value.');
            }
            foreach ($value['tokens'] as $name => $token) {
                if (! preg_match('/^(primary|secondary|background|text|radius)$/', (string) $name) || ! is_string($token) || ! preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,20}|\d+(?:px|rem|%)?)$/i', $token)) {
                    throw new InvalidArgumentException('Design tokens must be named safe CSS values.');
                }
            }

            return;
        }
        if ($key === 'spam') {
            if (! is_bool($value['turnstile_enabled'] ?? null) || ! in_array($value['analysis_mode'] ?? null, ['always', 'manual', 'eligible_only'], true)) {
                throw new InvalidArgumentException('Spam policy is invalid.');
            }

            return;
        }
        foreach (['resume_days' => [1, 365], 'retention_days' => [1, 3650], 'retry_attempts' => [0, 20], 'timeout_seconds' => [5, 3600]] as $field => [$min, $max]) {
            if (! isset($value[$field]) || filter_var($value[$field], FILTER_VALIDATE_INT) === false || (int) $value[$field] < $min || (int) $value[$field] > $max) {
                throw new InvalidArgumentException('Operations settings are invalid.');
            }
        }
    }
}

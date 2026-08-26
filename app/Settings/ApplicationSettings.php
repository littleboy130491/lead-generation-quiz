<?php

namespace App\Settings;

use App\Ai\Prompt\AnalysisPromptVariables;
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
        'notifications' => ['submission_emails' => []],
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
        if ($key === 'notifications') {
            $value['submission_emails'] = array_values(array_unique(array_map(
                fn (mixed $email): string => strtolower(trim((string) $email)),
                is_array($value['submission_emails'] ?? null) ? $value['submission_emails'] : [],
            )));
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
                if (! is_array($entry)) {
                    throw new InvalidArgumentException('Provider chains contain only safe provider and model pairs.');
                }
                $allowedEntryKeys = ['provider', 'model', 'endpoint_url'];
                if (array_diff(array_keys($entry), $allowedEntryKeys) !== [] || ! isset($entry['provider'], $entry['model'])) {
                    throw new InvalidArgumentException('Provider chains contain only safe provider and model pairs.');
                }
                $provider = (string) $entry['provider'];
                $model = (string) $entry['model'];
                if (! preg_match('/^[a-z0-9._-]{1,80}$/i', $provider) || ! preg_match('/^[a-z0-9._:-]{1,120}$/i', $model)) {
                    throw new InvalidArgumentException('Provider chains contain only safe provider and model pairs.');
                }
                if (array_key_exists('endpoint_url', $entry) && $entry['endpoint_url'] !== null && $entry['endpoint_url'] !== '') {
                    if ($provider !== 'openai-compatible') {
                        throw new InvalidArgumentException('Endpoint URL is only allowed for the custom OpenAI-compatible provider.');
                    }
                    $url = (string) $entry['endpoint_url'];
                    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                        throw new InvalidArgumentException('Custom provider endpoint URL must be a valid http or https URL.');
                    }
                } elseif ($provider === 'openai-compatible') {
                    throw new InvalidArgumentException('Custom OpenAI-compatible provider entries require an endpoint URL.');
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
            if (isset($value['report_template']) && is_string($value['report_template'])) {
                $invalid = app(AnalysisPromptVariables::class)->disallowedPlaceholders(
                    $value['report_template'],
                    allowPerQuestion: false,
                );
                if ($invalid !== []) {
                    throw new InvalidArgumentException('Analysis system prompts may only use {{questions_and_answers}}.');
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
        if ($key === 'notifications') {
            $emails = $value['submission_emails'] ?? null;
            if (! is_array($emails) || count($emails) > 20) {
                throw new InvalidArgumentException('Submission notification emails must be a list of at most 20 addresses.');
            }
            $seen = [];
            foreach ($emails as $email) {
                if (! is_string($email) || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Submission notification emails must be valid email addresses.');
                }
                $normalized = strtolower(trim($email));
                if (isset($seen[$normalized])) {
                    throw new InvalidArgumentException('Submission notification emails must be unique.');
                }
                $seen[$normalized] = true;
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

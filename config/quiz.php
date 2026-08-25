<?php

return [
    'resume_days' => (int) env('QUIZ_RESUME_DAYS', 30),
    'unlock_minutes' => (int) env('QUIZ_UNLOCK_MINUTES', 480),
    'reserved_slugs' => ['admin', 'webhooks', 'up', 'assets', 'build', 'storage', 'livewire', 'sanctum'],
    'analysis_provider_chain' => [],
    'mailgun_webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    'execution_lease_minutes' => (int) env('QUIZ_EXECUTION_LEASE_MINUTES', 5),
    'analysis_recovery' => [
        'stale_after_minutes' => (int) env('QUIZ_ANALYSIS_STALE_AFTER_MINUTES', 15),
        'max_attempts' => (int) env('QUIZ_ANALYSIS_MAX_ATTEMPTS', 3),
        'retry_backoff_minutes' => (int) env('QUIZ_ANALYSIS_RETRY_BACKOFF_MINUTES', 5),
    ],
    'delivery_recovery' => [
        'stale_after_minutes' => (int) env('QUIZ_DELIVERY_STALE_AFTER_MINUTES', 15),
        'max_attempts' => (int) env('QUIZ_DELIVERY_MAX_ATTEMPTS', 3),
        'retry_backoff_minutes' => (int) env('QUIZ_DELIVERY_RETRY_BACKOFF_MINUTES', 5),
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Debug Logging
    |--------------------------------------------------------------------------
    |
    | Opt-in diagnostics for synchronous provider calls. When enabled, every
    | step records its provider, model, wall time, token usage, finish reason,
    | and normalized failure so slow or truncated generations can be traced.
    | Credentials are never logged.
    |
    */

    'enabled' => env('AI_DEBUG_LOG', false),

    /*
    | Prompt and response bodies are logged only when this is also enabled.
    | Analysis calls carry respondent answers, so keep this off outside of a
    | deliberate debugging window and clear the log afterwards.
    */

    'log_content' => env('AI_DEBUG_LOG_CONTENT', false),

    'channel' => env('AI_DEBUG_LOG_CHANNEL', 'ai'),

    'max_content_characters' => (int) env('AI_DEBUG_LOG_MAX_CHARS', 4000),

];

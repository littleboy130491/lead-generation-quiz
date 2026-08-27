<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Synchronous Generation Behavior
    |--------------------------------------------------------------------------
    |
    | The quiz interview, quiz-definition, and analysis calls all produce a
    | schema-constrained object rather than open-ended prose. Reasoning models
    | spend substantial hidden tokens and wall time before answering, which can
    | exceed the request timeout, so providers that support toggling extended
    | reasoning are asked to disable it for these calls.
    |
    */

    'disable_reasoning' => env('AI_DISABLE_REASONING', true),

    /*
    | Upper bound on generated tokens per attempt. This is a runaway guard: set
    | it high enough for the largest quiz you expect, because a response cut off
    | at this limit is discarded by validation. A truncated response is reported
    | as a "length" finish reason in the AI debug log.
    */

    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 16000),

];

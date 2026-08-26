<?php

return [
    // Shared Bearer token for /api/v1 server-to-server routes (quiz generation, user provisioning).
    // Keep this value only in the environment. Generate it with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'token' => env('QUIZ_GENERATION_API_TOKEN'),
];

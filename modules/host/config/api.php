<?php

/*
|--------------------------------------------------------------------------
| API foundation: rate limits, idempotency retention, docs and health
|--------------------------------------------------------------------------
|
| T-021. Every limit here is configuration, not contract (D-9): a limit of 0
| or a non-integer is refused at boot by App\Support\Api\ApiLimits rather
| than silently disabling throttling.
|
*/

return [

    'limits' => [
        'tenant' => env('API_RATE_LIMIT_TENANT', 1200),
        'user' => env('API_RATE_LIMIT_USER', 120),
        'terminal' => env('API_RATE_LIMIT_TERMINAL', 300),
        'unauthenticated' => env('API_RATE_LIMIT_UNAUTHENTICATED', 30),
    ],

    'idempotency' => [
        'retention_hours' => (int) env('API_IDEMPOTENCY_RETENTION_HOURS', 24),
        'claim_timeout_seconds' => (int) env('API_IDEMPOTENCY_CLAIM_TIMEOUT', 120),
        'in_progress_retry_after' => 1,
    ],

    /*
    | D-11: GET /api/v1/openapi.json answers 404 unless this is true. The
    | generator command (api:openapi) always works regardless of this flag.
    */
    'docs_enabled' => (bool) env('API_DOCS_ENABLED', false),

    'health' => [
        'timeout_seconds' => (int) env('HEALTH_CHECK_TIMEOUT', 2),
    ],

];

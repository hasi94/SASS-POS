<?php

/*
|--------------------------------------------------------------------------
| Event bus and outbox: names and numbers
|--------------------------------------------------------------------------
|
| T-018, in config/audit.php's established style: every value here is a
| name or a count. Every number is validated where it is used - a
| non-positive value throws, and batch sizes are clamped to at most 500 -
| so a typo here fails loudly rather than producing a silently different
| schedule.
|
*/

return [

    /*
    | Which EventBus implementation serves this process: `redis` (shipped
    | default) or `memory`. Chosen by configuration, never by module code
    | (AC-1). `memory` is refused outside a local environment
    | (Flexipos\Eventbus\Bus\EventBusDriver, Ruling 8, AC-4).
    */
    'driver' => env('EVENTBUS_DRIVER', 'redis'),

    /*
    | The one event stream every tenant publishes onto and every declared
    | consumer group reads from (Ruling 3).
    */
    'stream' => env('EVENTBUS_STREAM', 'flexipos:events'),

    /*
    | Where a message goes after it exhausts its delivery attempts (D-7).
    */
    'dead_letter_stream' => env('EVENTBUS_DEAD_LETTER_STREAM', 'flexipos:events:dead'),

    /*
    | The approximate MAXLEN ~ the Redis adapter trims the event stream to
    | (docs/deployment-vps.md; risk 4). The stand-in never trims.
    */
    'max_length' => 100000,

    'redis' => [
        'connection' => env('EVENTBUS_REDIS_CONNECTION', 'default'),
    ],

    /*
    | The relay (Ruling 6): how many pending outbox_events rows it claims
    | per pass, how many publish attempts a row gets before it is marked
    | failed, the exponential back-off between attempts, and how long the
    | worker sleeps between passes.
    */
    'relay' => [
        'batch_size' => 100,
        'max_attempts' => 5,
        'backoff_base_seconds' => 30,
        'backoff_cap_seconds' => 600,
        'poll_seconds' => 1,
    ],

    /*
    | The consumer runner (Ruling 7): how many stream entries it reads per
    | pass, how many deliveries before a message is dead-lettered, the
    | claim timeout a message must be idle for before it is reclaimed, the
    | back-off cap between reclaims, and how long the worker sleeps between
    | passes.
    */
    'consumer' => [
        'batch_size' => 10,
        'max_deliveries' => 5,
        'claim_timeout_seconds' => 60,
        'backoff_cap_seconds' => 900,
        'poll_seconds' => 1,
    ],

];

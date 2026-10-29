<?php

/*
|--------------------------------------------------------------------------
| Plugin framework: names and numbers
|--------------------------------------------------------------------------
|
| T-019, in config/eventbus.php's established style: every value here is a
| name or a count. Every number is validated where it is used - a
| non-positive value throws - so a typo here fails loudly rather than
| producing a silently different threshold or timeout.
|
*/

return [

    /*
    | The core version every package's composer.json declares. Checked
    | against a manifest's `core_constraint` (AC-10, plan Ruling 3). One
    | configuration value, not an environment key.
    */
    'core_version' => '0.1.0',

    'breaker' => [
        /*
        | Consecutive synchronous or after-mode failures, for one
        | (tenant, plugin) pair, before the gateway trips that pair's
        | breaker (D-6, AC-31).
        */
        'threshold' => 5,
    ],

    /*
    | The per-call timeout a synchronous handler invocation is measured
    | against, after the call completes (D-5, plan Ruling 5). A slow
    | handler's result is discarded and its writes are rolled back.
    */
    'timeout_ms' => 500,

    /*
    | Where UninstallPlugin exports a plugin's own tenant-side data before
    | dropping its tables (AC-20, plan Ruling 8).
    */
    'export_path' => storage_path('app/plugin-exports'),

];

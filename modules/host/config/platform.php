<?php

/*
|--------------------------------------------------------------------------
| Platform: entitlement cache, the grace period default, and the stand-in
|--------------------------------------------------------------------------
|
| T-016. `entitlements_ttl` mirrors `tenancy.mapping.ttl` (D-15): the same
| 300-second window, for the same reason. `past_due_grace_days` is the
| configured fallback `GracePeriod` uses when `platform_settings` carries no
| row or a malformed one (D-12, Ruling 15) - the master row, when present, is
| the authority.
|
| `stand_in.entitlements` is the local mapping stand-in
| `ConfigEntitlementSource` reads when `TENANCY_MAPPING_SOURCE=config`
| selects it (mirrors `tenancy.databases`). It defaults to empty, so every
| fixture tenant stays uncapped exactly as it was before this task.
|
*/

return [

    'entitlements_ttl' => (int) env('PLATFORM_ENTITLEMENTS_TTL', 300),

    'past_due_grace_days' => (int) env('PLATFORM_PAST_DUE_GRACE_DAYS', 7),

    'stand_in' => [
        'entitlements' => [],
    ],

    // T-026 (FP-206), Ruling 2: the compare-and-set lease window a stale
    // `running` provisioning run is reclaimed through. Nothing auto-resumes;
    // this only decides when `--resume` is *permitted* to try.
    'provisioning' => [
        'stale_after_minutes' => (int) env('PLATFORM_PROVISIONING_STALE_AFTER_MINUTES', 30),
    ],

];

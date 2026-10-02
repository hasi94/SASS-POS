<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booted modules
    |--------------------------------------------------------------------------
    |
    | A comma separated list of the modules (directories under
    | modules/) this host process should boot, for example "identity,catalog".
    | Whitespace around each name is ignored and duplicates are collapsed.
    |
    | An empty or unset value is valid: the host then boots with zero module
    | packages, which is a legitimate minimal configuration. A name that has no
    | matching package under modules/ makes the host fail fast at boot.
    |
    |--------------------------------------------------------------------------
    | Caching in production: four constraints, one of them guarded
    |--------------------------------------------------------------------------
    |
    | The host boots modules at runtime, from this value and from each
    | package's own composer.json. Laravel's optimisation commands and Composer's
    | authoritative classmap all freeze part of that at build time, so read this
    | before running any of them in an image.
    |
    | 1. `php artisan config:cache` bakes MODULES into bootstrap/cache/config.php
    |    and the process never reads the variable again, so one image can no
    |    longer run a different module subset per deployment stage - the single
    |    guarantee this host exists for.
    |    GUARDED IN CODE: ModuleHostServiceProvider::guardAgainstStaleCachedModules()
    |    throws at boot when the configuration is cached AND the process
    |    environment sets a MODULES value that parses differently from the
    |    frozen one. It stays silent when the configuration is not cached, when
    |    the environment sets no MODULES at all, and when the two agree.
    |    LIMIT OF THE GUARD, worth knowing before you rely on it: it reads the
    |    real process environment only. While the configuration is cached the
    |    framework does not load .env at all, so a MODULES changed there after
    |    caching is invisible to the application AND to this guard - the process
    |    boots the frozen list in silence. On a host where MODULES lives in
    |    .env (the XAMPP/VPS route rather than a container one), re-run
    |    `php artisan config:clear && php artisan config:cache` after every edit,
    |    or do not cache configuration there at all.
    |
    | 2. A module provider's mergeConfigFrom() is a no-op while the
    |    configuration is cached, so anything a module contributes that way is
    |    missing from the running process. Since FP-201 no module contributes a
    |    database connection that way at all - a module declares none, and all
    |    business data goes through the shared `tenant` connection, which is
    |    built at runtime by Flexipos\Kernel\Tenancy\Tenancy and is therefore
    |    immune to a cached configuration - so the remaining exposure is only a
    |    module that merges some other configuration file of its own.
    |    DOCUMENTED ONLY (decision D-20). Constraint 1's guard closes the
    |    dangerous half structurally: whatever a module merged before
    |    `config:cache` ran is inside the cache file already, and a module that
    |    was not in MODULES when the cache was built can no longer boot for the
    |    first time under a disagreeing cached value.
    |
    | 3. `php artisan route:cache` freezes the route table the same way, so a
    |    module added to MODULES afterwards is unreachable until the cache is
    |    rebuilt.
    |    DOCUMENTED ONLY (decision D-20): no code detects a route table that was
    |    cached before a module joined MODULES.
    |    The command itself succeeds - measured, T-004. Earlier revisions of this
    |    entry said it could not run here at all because routes/web.php used an
    |    inline closure. That was never true on this framework version: Laravel 12
    |    serialises a closure action with SerializableClosure::unsigned(). Three
    |    closure routes remain (`/up`, and `storage/{path}` twice) and route
    |    caching handles them, which is why
    |    tests/Feature/RouteCachingTest.php pins both halves as assertions
    |    instead of leaving them as prose nobody re-measures.
    |
    | 4. Building the Composer autoloader with `--classmap-authoritative` breaks
    |    the boot mechanism entirely: such a loader answers "not found" for any
    |    class outside the classmap it was built with, no matter what
    |    ModuleRegistry adds with addPsr4() afterwards, so every module fails.
    |    DOCUMENTED ONLY (decision D-20): no detection code is added in T-002.
    |
    */

    'modules' => env('MODULES', ''),

];

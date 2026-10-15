<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The tenant migration tree
    |--------------------------------------------------------------------------
    |
    | Where the per-tenant baseline lives, relative to the host's base path.
    | This key is the **one authority** for that location: `schema:publish`
    | builds the catalogue from it, the runner migrates from it, and the tests
    | that apply or scan the tree read it rather than repeating the directory
    | name. A second literal beside this one is how the tree moves in one place
    | and stays put in another.
    |
    | The value is relative because that is what `migrate --path` takes; a
    | caller that needs an absolute path builds it with base_path().
    |
    | Laravel does not auto-discover this directory, which is what makes
    | `php artisan migrate` structurally incapable of applying the tenant
    | baseline to the master database (AC-22).
    |
    */

    'tenant_migration_path' => 'database/migrations-tenant',

    /*
    |--------------------------------------------------------------------------
    | The committed catalogue fixture
    |--------------------------------------------------------------------------
    |
    | The catalogue as the branch declares it, relative to the base path.
    | `schema:publish` writes `schema_versions` from the tree and compares the
    | result with this file, so a migration added without publishing - or a
    | published migration whose content changed - is a difference in a committed
    | file that a reviewer sees (AC-31, AC-32).
    |
    */

    'catalogue_fixture' => 'database/catalogue/tenant_schema_versions.json',

    /*
    |--------------------------------------------------------------------------
    | The serving version floor is not here
    |--------------------------------------------------------------------------
    |
    | `required_version` used to live here and read APP_REQUIRED_SCHEMA_VERSION
    | a second time. It is removed (step 7a, ruling 13.1): one variable read in
    | two config files is the shape decision D-14 rules against, and nothing
    | consumed this copy but a type assertion. The floor's one site is
    | `tenancy.required_schema_version`, and anything here that needs "the
    | highest version the shipped tree can produce" reads the **catalogue** -
    | a different fact from "the floor this deployment chose", and the reason
    | the floor is allowed to lag behind the tree (AC-21).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | The runner
    |--------------------------------------------------------------------------
    |
    | `max_attempts` is decision D-20: a job is retried twice and then stays
    | failed, so a migration that fails for a structural reason stops rather
    | than hammering every tenant. `claim_ttl_minutes` is how long a claim is
    | believed before another worker may take the job - the expiry a dead worker
    | leaves behind. `per_server_limit` caps how many tenants on one database
    | server are migrated at once, because the limit that matters is the
    | server's, not the queue's. `queue` is the connection's queue name.
    |
    */

    'max_attempts' => (int) env('SCHEMA_MAX_ATTEMPTS', 3),

    'claim_ttl_minutes' => (int) env('SCHEMA_CLAIM_TTL_MINUTES', 30),

    'per_server_limit' => (int) env('SCHEMA_PER_SERVER_LIMIT', 2),

    'queue' => env('SCHEMA_QUEUE', 'migrations'),

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Decision D-10, fail-closed: while this is false the runner refuses a
    | migration the catalogue marks destructive, at plan time, before a single
    | job row is written and before any tenant connection is opened. The row
    | that ships a backup facility flips this one line instead of hunting for a
    | thrown exception.
    |
    */

    'backups' => [
        'available' => (bool) env('SCHEMA_BACKUPS_AVAILABLE', false),
    ],

];

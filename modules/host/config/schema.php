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
    | The schema version this code requires
    |--------------------------------------------------------------------------
    |
    | The catalogue's highest version is the only authority for what the shipped
    | tree can produce, so this constant is **not** given a baseline number as a
    | default here: that would be the catalogue's count written out a second
    | time, and the two would disagree the first time a migration was added.
    | `.env.example` names the value a deployment ships with, and a test asserts
    | that it is not greater than the catalogue's highest version - a code that
    | requires a version nothing can produce would refuse every tenant (AC-21).
    |
    | Zero means "no version floor configured": the serving gate refuses
    | nothing, and `tenant_databases.status` is still the gate that stops a
    | half-provisioned tenant being served.
    |
    */

    'required_version' => (int) env('APP_REQUIRED_SCHEMA_VERSION', 0),

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

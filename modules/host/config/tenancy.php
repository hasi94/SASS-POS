<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection names
    |--------------------------------------------------------------------------
    |
    | `connection` is the Laravel connection name every tenant-scoped query
    | runs through, and the only stanza Flexipos\Kernel\Tenancy\Tenancy ever
    | writes. `master_connection` is the always-configured platform connection.
    | `placeholder_driver` is the sentinel driver config/database.php parks the
    | tenant stanza on while no tenant is resolved; the connection guard treats
    | it as "not configured yet" rather than trying to build it.
    |
    */

    'connection' => 'tenant',

    'master_connection' => 'master',

    'placeholder_driver' => 'flexipos-no-tenant',

    /*
    |--------------------------------------------------------------------------
    | Allowed tenant database drivers
    |--------------------------------------------------------------------------
    |
    | A whitelist applied to a mapping record's driver before a connection is
    | built, so a mapping row can never smuggle an unexpected driver into the
    | process. sqlite is deliberately NOT in the default: only the test suite
    | adds it, through TENANCY_ALLOWED_DRIVERS in phpunit.xml.
    |
    */

    'allowed_drivers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TENANCY_ALLOWED_DRIVERS', 'mysql,mariadb'))
    ), static fn (string $driver): bool => $driver !== '')),

    /*
    |--------------------------------------------------------------------------
    | Where the mapping is read from
    |--------------------------------------------------------------------------
    |
    | `master` is the shipped source (FP-205): one joined read of
    | tenant_databases + database_servers on the master connection, behind a
    | 300-second cache keyed by tenant id. `config` is the local stand-in that
    | reads the `databases` key below and queries nothing - it is what T-003
    | shipped, and the tenancy fixtures still use it so their proofs need no
    | master schema.
    |
    | `ttl` is the cache window in seconds. The credential version travels as a
    | value inside the entry rather than in the key, so a rotation cannot leave
    | an entry behind that nothing will ever evict.
    |
    */

    'mapping' => [
        'source' => env('TENANCY_MAPPING_SOURCE', 'master'),
        'ttl' => (int) env('TENANCY_MAPPING_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | The tenant schema version this code requires
    |--------------------------------------------------------------------------
    |
    | The floor the **serving** version gate compares a mapping's
    | schema_version against: below it, a request for that tenant is refused
    | with a named exception before any network connection is attempted, and
    | the host renders it as HTTP 503 with Retry-After. Entering a tenant for
    | the *migration* purpose is exempt from this gate and only from this gate -
    | a tenant below the required version is the only kind the runner migrates.
    |
    | The same variable config/schema.php reads, and the catalogue is the
    | authority for its value: see the note there, and .env.example, which
    | ships the baseline's highest version. Zero means no version floor.
    |
    */

    'required_schema_version' => (int) env('APP_REQUIRED_SCHEMA_VERSION', 0),

    /*
    |--------------------------------------------------------------------------
    | How long a client should wait when a tenant is behind
    |--------------------------------------------------------------------------
    |
    | The Retry-After header's value, in seconds, on the 503 a behind tenant
    | gets. It is a hint for a client, not a promise about the migration.
    |
    */

    'behind_retry_after' => (int) env('TENANCY_BEHIND_RETRY_AFTER', 30),

    /*
    |--------------------------------------------------------------------------
    | Local tenant-to-database mapping
    |--------------------------------------------------------------------------
    |
    | The development stand-in for the master database's tenant_databases +
    | database_servers join (docs/database.md section 4.4), read by
    | InMemoryTenantDatabaseRepository. FP-203 replaces the repository with one
    | backed by the real master schema and this key becomes unused.
    |
    | One entry per tenant, keyed by the tenant identifier:
    |
    |   '01J0000000000000000000000A' => [
    |       'driver' => 'mysql',
    |       'host' => '127.0.0.1',
    |       'port' => 3306,
    |       'database' => 'flx_t_000123',
    |       'username' => 'flx_t000123_app',
    |       'credential_ref' => 'env://TENANT_000123_APP_PASSWORD',
    |       'status' => 'active',
    |   ],
    |
    | Never a password: the record carries a reference, resolved at connect
    | time by the SecretResolver.
    |
    */

    'databases' => [],

];

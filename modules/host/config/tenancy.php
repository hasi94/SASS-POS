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

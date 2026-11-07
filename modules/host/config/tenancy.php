<?php

/*
|--------------------------------------------------------------------------
| The serving version floor, read once, before the array
|--------------------------------------------------------------------------
|
| Read here rather than inline below because absence has to survive as
| absence: `(int) env('APP_REQUIRED_SCHEMA_VERSION', 0)` folded "nobody chose
| anything" into "no floor" and the serving gate then had no way to tell them
| apart, which is risk R-14. An unset or empty variable yields **null** - a
| misconfiguration the gate refuses to serve on - and only a value someone
| actually wrote becomes a number, including an explicit 0.
|
*/

$requiredSchemaVersion = env('APP_REQUIRED_SCHEMA_VERSION');

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
    | **This key is the floor's only site.** config/schema.php used to read the
    | same variable a second time, which is the shape decision D-14 rules
    | against; anything needing "the highest version the shipped tree can
    | produce" reads the catalogue instead, because that is a different fact
    | from "the floor this deployment chose". .env.example names the value a
    | deployment ships with, and a test pins it against the catalogue.
    |
    | Three states, not two (ruling 13.1):
    |
    | - **a number** - the floor, as AC-19 and AC-20 describe it;
    | - **an explicit 0** - "no floor; tenant_databases.status is the only
    |   guard", a choice someone wrote down, and served;
    | - **absent** - nobody chose anything. That is a misconfiguration, and a
    |   serving entry is refused with RequiredSchemaVersionNotConfigured and
    |   rendered 503 *without* Retry-After, because waiting does not help. The
    |   migrating purpose stays exempt: refusing it would disable
    |   `php artisan tenant:migrate`, the one command that fixes the condition.
    |
    */

    'required_schema_version' => is_numeric($requiredSchemaVersion)
        ? (int) $requiredSchemaVersion
        : null,

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

    /*
    |--------------------------------------------------------------------------
    | Writable secrets (T-026 D-6, D-29)
    |--------------------------------------------------------------------------
    |
    | `file://` is the only writable credential driver this project builds
    | (D-6); every reference must resolve under `file_root`, after `..` and
    | symlinks are resolved. The default is a sensible local path under this
    | project's existing storage convention; the real VPS placement and
    | backup strategy are deliberately deferred to FP-221 (D-29) - this key
    | only has to be configurable, not correct for a deployment that does not
    | exist yet.
    |
    | `provisioning.driver` selects the real-engine database/user-creation
    | implementation; the default is "refusing", which throws rather than
    | reach a live server. It is bound to "mysql" only when a deployment sets
    | this explicitly - never by the test suite (ENGINE_HOLD, T-026 Ruling 4).
    | `provisioning.user_host` is the MySQL/MariaDB host pattern a created
    | user is scoped to.
    |
    */

    'secrets' => [
        'file_root' => env('TENANCY_SECRETS_FILE_ROOT', storage_path('secrets')),
    ],

    'provisioning' => [
        'driver' => env('TENANCY_PROVISIONING_DRIVER', 'refusing'),
        'user_host' => env('TENANCY_PROVISIONING_USER_HOST', '%'),
        'admin_username' => env('TENANCY_PROVISIONING_ADMIN_USERNAME', 'root'),
        // The charset/collation `create_database` asks for (T-026 AC-16),
        // mirroring config/database.php's own mysql/mariadb connection
        // defaults - a tenant database is created the same way the master
        // connection already is, not a second convention.
        'charset' => env('TENANCY_PROVISIONING_CHARSET', 'utf8mb4'),
        'collation' => env('TENANCY_PROVISIONING_COLLATION', 'utf8mb4_unicode_ci'),
    ],

];

<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * T-004 FP-164: the shared tenancy fixture.
 *
 * These helpers used to be declared at file scope inside
 * Feature/TenancyResolutionTest.php while two other Feature files called them,
 * so running either of those two on its own died with "Call to undefined
 * function tenancyBootFixture()" - a full-suite run only worked because
 * PHPUnit happened to collect the declaring file first.
 *
 * They stay plain functions with the same names, so no call site changes and no
 * assertion changes meaning, and they stay under packages/kernel/tests, which
 * is the only home that keeps them inside phpstan.neon.dist's and deptrac.yaml's
 * `paths` (T-002 AC-16/AC-17).
 *
 * Every file that calls one of these must `require_once` this file itself, not
 * rely on another test file having been collected first. require_once makes a
 * double include harmless.
 *
 * Two fake tenants, each a real SQLite file holding a `marker` table with its
 * own name in it, so "the query reached tenant A's database" is a fact about
 * data rather than about configuration. No MySQL server, no RefreshDatabase and
 * no migration is involved: the mapping source is the in-memory implementation
 * of the AC-24 contract (AC-25).
 */

/**
 * Where the throwaway SQLite files live. Under storage/, which is git-ignored,
 * excluded from Pint and never analysed.
 *
 * Memoised because tenancyDeleteFixtureRoot() runs from an afterAll hook, which
 * Pest executes outside the application lifecycle: storage_path() is not
 * available there. The value is a constant for the life of the process, so
 * resolving it on the first beforeEach - when the application does exist - and
 * keeping it is safe.
 */
function tenancyFixtureRoot(): string
{
    static $root = null;

    if ($root === null) {
        $root = storage_path('app'.DIRECTORY_SEPARATOR.'tenancy-fixture');
    }

    return $root;
}

function tenancyFixtureDatabasePath(string $tenant): string
{
    return tenancyFixtureRoot().DIRECTORY_SEPARATOR.$tenant.'.sqlite';
}

/**
 * The one distinctive password every credential-hygiene assertion looks for.
 */
function tenancyFixturePassword(): string
{
    return 's3cret-DO-NOT-LEAK-7f3a';
}

/**
 * A SQLite database with a single-row `marker` table naming the tenant it
 * belongs to. Built with a bare PDO on purpose: going through the connection
 * manager would be the very thing under test.
 */
function tenancyCreateFixtureDatabase(string $tenant): string
{
    $path = tenancyFixtureDatabasePath($tenant);

    File::ensureDirectoryExists(dirname($path));
    File::delete($path);
    File::put($path, '');

    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('create table marker (value varchar(50) not null)');
    $pdo->exec('insert into marker (value) values ('.$pdo->quote($tenant).')');

    return $path;
}

/**
 * Write (or remove) a process environment variable so Env::getRepository() sees
 * it. $_SERVER as well as $_ENV, because Laravel's repository reads $_SERVER
 * first.
 */
function tenancyWriteEnvironment(string $variable, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$variable], $_SERVER[$variable]);
        putenv($variable);

        return;
    }

    $_ENV[$variable] = $value;
    $_SERVER[$variable] = $value;
    putenv($variable.'='.$value);
}

/**
 * The fake mapping: two servable tenants, two non-active ones, one whose
 * database cannot be opened and one asking for a driver the platform forbids.
 *
 * host/port/username are distinct per tenant and deliberately meaningless to
 * SQLite: they exist so AC-6 can assert the connection was configured *from the
 * record*.
 *
 * @return array<string, array<string, mixed>>
 */
function tenancyFixtureMapping(): array
{
    return [
        'tenant-a' => [
            'driver' => 'sqlite',
            'host' => 'db-fixture-a',
            'port' => 3307,
            'database' => tenancyFixtureDatabasePath('tenant-a'),
            'username' => 'flx_ta_app',
            'credential_ref' => 'env://TENANT_A_APP_PASSWORD',
            'status' => 'active',
        ],
        'tenant-b' => [
            'driver' => 'sqlite',
            'host' => 'db-fixture-b',
            'port' => 3308,
            'database' => tenancyFixtureDatabasePath('tenant-b'),
            'username' => 'flx_tb_app',
            'credential_ref' => 'env://TENANT_B_APP_PASSWORD',
            'status' => 'active',
        ],
        'tenant-suspended' => [
            'driver' => 'sqlite',
            'host' => 'db-fixture-a',
            'port' => 3307,
            'database' => tenancyFixtureDatabasePath('tenant-a'),
            'username' => 'flx_ta_app',
            'credential_ref' => 'env://TENANT_A_APP_PASSWORD',
            'status' => 'suspended',
        ],
        'tenant-provisioning' => [
            'driver' => 'sqlite',
            'host' => 'db-fixture-a',
            'port' => 3307,
            'database' => tenancyFixtureDatabasePath('tenant-a'),
            'username' => 'flx_ta_app',
            'credential_ref' => 'env://TENANT_A_APP_PASSWORD',
            'status' => 'provisioning',
        ],
        'tenant-unopenable' => [
            'driver' => 'sqlite',
            'host' => 'db-fixture-a',
            'port' => 3307,
            'database' => tenancyFixtureRoot().DIRECTORY_SEPARATOR.'no-such-database.sqlite',
            'username' => 'flx_ta_app',
            'credential_ref' => 'env://TENANT_A_APP_PASSWORD',
            'status' => 'active',
        ],
        'tenant-wrong-driver' => [
            'driver' => 'pgsql',
            'host' => 'db-fixture-a',
            'port' => 5432,
            'database' => tenancyFixtureDatabasePath('tenant-a'),
            'username' => 'flx_ta_app',
            'credential_ref' => 'env://TENANT_A_APP_PASSWORD',
            'status' => 'active',
        ],
    ];
}

/**
 * Build the two SQLite files once per file rather than once per test.
 *
 * T-004 FP-155 (AC-6). Building the pair was measured by the architect at
 * ~95.5 ms per cycle on this machine (2026-09-29, 67 cycles benchmarked). Three
 * Feature files require this helper and they hold 46 cases between them, so every
 * one of those cases used to pay it and now each file pays it once. Both numbers
 * are someone's measurement on one machine, not a guarantee - the reason for the
 * change is the per-case/per-file ratio, which does not depend on either.
 *
 * Safe because nothing in those three files ever writes to a fixture database -
 * the only DDL or DML against them is inside tenancyCreateFixtureDatabase() - so
 * the content a case reads is identical whether the file was built this test or
 * three tests ago. TenancyResolutionTest's last case asserts that directly.
 *
 * Idempotent rather than hoisted into beforeAll, on purpose: beforeAll runs
 * outside the application lifecycle, where storage_path() and the File facade do
 * not exist. This keeps creation inside beforeEach, where they do, and skips the
 * work when the file is already there. Deletion is what moves to afterAll - see
 * tenancyDeleteFixtureRoot().
 */
function tenancyEnsureFixtureDatabases(): void
{
    foreach (['tenant-a', 'tenant-b'] as $tenant) {
        if (! is_file(tenancyFixtureDatabasePath($tenant))) {
            tenancyCreateFixtureDatabase($tenant);
        }
    }
}

/**
 * Create the fixture databases, publish the credentials and point the mapping
 * source at the fake mapping.
 */
function tenancyBootFixture(): void
{
    tenancyEnsureFixtureDatabases();

    tenancyWriteEnvironment('TENANT_A_APP_PASSWORD', tenancyFixturePassword());
    tenancyWriteEnvironment('TENANT_B_APP_PASSWORD', tenancyFixturePassword());

    config()->set('tenancy.databases', tenancyFixtureMapping());

    // The repository is a singleton built from config, so it has to be dropped
    // after the config changes.
    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();
}

/**
 * Return the process to "no tenant resolved" whatever a test left behind, and
 * remove the credentials from the environment.
 *
 * Per test, because all of it is per-application state Laravel rebuilds between
 * tests: the scoped instances, the resolved tenant, the two environment
 * variables. Only the two SQLite files are shared across a file, and only they
 * are removed by tenancyDeleteFixtureRoot() in afterAll.
 */
function tenancyForgetFixture(): void
{
    // A fresh scope gives a Tenancy with depth 0 and an empty context, so this
    // single leave() always reaches the teardown branch.
    app()->forgetScopedInstances();
    app(Tenancy::class)->leave();

    tenancyWriteEnvironment('TENANT_A_APP_PASSWORD', null);
    tenancyWriteEnvironment('TENANT_B_APP_PASSWORD', null);
}

/**
 * Remove the shared fixture directory, from an afterAll hook.
 *
 * Native calls only: Pest runs afterAll outside the application lifecycle, so
 * the File facade would resolve against no container. The directory only ever
 * holds the two SQLite files, so one pass over glob() empties it. rmdir() after
 * clearstatcache() covers the Windows case where a file handle outlives the
 * unlink - the same hazard deleteFixtureDirectory() handles on the host side.
 *
 * Leaving the directory behind would be a real defect and not merely untidy:
 * `tenant-unopenable` in tenancyFixtureMapping() points at a file inside it that
 * must never exist.
 */
function tenancyDeleteFixtureRoot(): void
{
    $root = tenancyFixtureRoot();

    foreach (glob($root.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    clearstatcache();

    if (is_dir($root)) {
        rmdir($root);
    }
}

function tenancy(): Tenancy
{
    return app(Tenancy::class);
}

/**
 * The at-rest tenant stanza, exactly as config/database.php ships it.
 *
 * @return array<string, mixed>
 */
function tenancyPlaceholderStanza(): array
{
    return [
        'driver' => 'flexipos-no-tenant',
        'database' => '',
        'prefix' => '',
    ];
}

/**
 * @return array<string, mixed>
 */
function tenancyTenantStanza(): array
{
    return (array) config('database.connections.tenant');
}

/**
 * The tenant stanza with the credential replaced by its own type name.
 *
 * The stanza holds a ConnectionPassword object, so two stanzas built for the
 * same tenant can never be identical under toBe(). Substituting the *type* keeps
 * an AC-17 comparison honest rather than merely possible: every other key is
 * still compared by value, and a regression that put a plain string back in the
 * stanza changes this marker too. The instance itself is asserted separately,
 * by class, wherever it matters.
 *
 * @return array<string, mixed>
 */
function tenancyComparableStanza(): array
{
    $stanza = tenancyTenantStanza();

    if (array_key_exists('password', $stanza)) {
        $stanza['password'] = 'password:'.get_debug_type($stanza['password']);
    }

    return $stanza;
}

/**
 * What the `marker` table of whichever database the tenant connection currently
 * points at says.
 */
function tenancyMarker(): string
{
    return (string) DB::connection('tenant')->scalar('select value from marker');
}

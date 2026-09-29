<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\InvalidTenantIdentifier;
use Flexipos\Kernel\Tenancy\Exceptions\TenantAlreadyResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotActive;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotMapped;
use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
 * AC-6, AC-7, AC-9, AC-10, AC-11.
 *
 * Two fake tenants, each a real SQLite file holding a `marker` table with its
 * own name in it, so "the query reached tenant A's database" is a fact about
 * data rather than about configuration. No MySQL server, no RefreshDatabase and
 * no migration is involved: the mapping source is the in-memory implementation
 * of the AC-24 contract (AC-25).
 *
 * The tenancy fixture helpers below are shared with the other three Feature
 * files in this package, the same way QaToolchainRegistryCoverageTest's
 * qaFixturePackageNames() is shared inside services/host/tests.
 */

uses(TestCase::class);

/**
 * Where the throwaway SQLite files live. Under storage/, which is git-ignored,
 * excluded from Pint and never analysed.
 */
function tenancyFixtureRoot(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'tenancy-fixture');
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
 * Create the fixture databases, publish the credentials and point the mapping
 * source at the fake mapping.
 */
function tenancyBootFixture(): void
{
    tenancyCreateFixtureDatabase('tenant-a');
    tenancyCreateFixtureDatabase('tenant-b');

    tenancyWriteEnvironment('TENANT_A_APP_PASSWORD', tenancyFixturePassword());
    tenancyWriteEnvironment('TENANT_B_APP_PASSWORD', tenancyFixturePassword());

    config()->set('tenancy.databases', tenancyFixtureMapping());

    // The repository is a singleton built from config, so it has to be dropped
    // after the config changes.
    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();
}

/**
 * Return the process to "no tenant resolved" whatever a test left behind, then
 * remove the fixture from disk and from the environment.
 */
function tenancyForgetFixture(): void
{
    // A fresh scope gives a Tenancy with depth 0 and an empty context, so this
    // single leave() always reaches the teardown branch.
    app()->forgetScopedInstances();
    app(Tenancy::class)->leave();

    tenancyWriteEnvironment('TENANT_A_APP_PASSWORD', null);
    tenancyWriteEnvironment('TENANT_B_APP_PASSWORD', null);

    File::deleteDirectory(tenancyFixtureRoot());
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

beforeEach(function () {
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

it('configures the tenant connection from the mapping record and reaches that database', function () {
    tenancy()->run('tenant-a', function (): void {
        $stanza = tenancyTenantStanza();

        // AC-6: configured from the record, not from any DB_* variable.
        expect($stanza['driver'])->toBe('sqlite')
            ->and($stanza['host'])->toBe('db-fixture-a')
            ->and($stanza['port'])->toBe(3307)
            ->and($stanza['database'])->toBe(tenancyFixtureDatabasePath('tenant-a'))
            ->and($stanza['username'])->toBe('flx_ta_app')
            ->and($stanza[Tenancy::TENANT_MARKER])->toBe('tenant-a');

        // AC-7.
        expect(tenancy()->current()->hasTenant())->toBeTrue()
            ->and(tenancy()->current()->tenantId())->toBe('tenant-a')
            ->and(tenancy()->current()->database())->toBeInstanceOf(TenantDatabase::class)
            ->and(tenancy()->current()->database()->database)->toBe(tenancyFixtureDatabasePath('tenant-a'));

        // AC-6: and the query actually lands in tenant A's database.
        expect(tenancyMarker())->toBe('tenant-a');
    });

    expect(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());
});

it('reaches a second, distinguishable tenant database with the same code', function () {
    expect(tenancy()->run('tenant-b', fn (): string => tenancyMarker()))->toBe('tenant-b');
});

it('never puts the credential in the connection configuration it hands out to readers', function () {
    // AC-8's other half: the value is dereferenced from the record's reference,
    // which is the only credential-shaped thing the record carries.
    tenancy()->run('tenant-a', function (): void {
        $record = tenancy()->current()->database();

        expect($record->credentialRef)->toBe('env://TENANT_A_APP_PASSWORD');

        // The stanza does hold the resolved credential - it has to, to connect -
        // but as a ConnectionPassword, not a bare string, and it exists only
        // inside the tenant scope.
        $password = tenancyTenantStanza()['password'];

        expect($password)->toBeInstanceOf(ConnectionPassword::class)
            // It still coerces to the real value, which is what the connector
            // relies on.
            ->and((string) $password)->toBe(tenancyFixturePassword())
            // And it says nothing under the representations that are not that
            // deliberate coercion.
            ->and(print_r($password, true))->not->toContain(tenancyFixturePassword())
            ->and((string) json_encode($password))->not->toContain(tenancyFixturePassword());
    });

    expect(tenancyTenantStanza())->not->toHaveKey('password');
});

it('refuses a tenant with no mapping record at all, naming it, and changes nothing', function () {
    $before = tenancyTenantStanza();

    expect($before)->toBe(tenancyPlaceholderStanza());

    expect(function (): void {
        tenancy()->enter('tenant-does-not-exist');
    })
        ->toThrow(TenantDatabaseNotMapped::class, 'tenant-does-not-exist');

    // AC-9: no partial overwrite, and no tenant resolved.
    expect(tenancyTenantStanza())->toBe($before)
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
});

it('refuses a tenant whose status is not active, naming the actual status', function (string $tenantId, string $status) {
    $before = tenancyTenantStanza();

    expect(function () use ($tenantId): void {
        tenancy()->enter($tenantId);
    })
        ->toThrow(TenantDatabaseNotActive::class, $status);

    // AC-10: state is exactly as it was, for every non-active status.
    expect(tenancyTenantStanza())->toBe($before)
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
})->with([
    'suspended' => ['tenant-suspended', 'suspended'],
    'provisioning' => ['tenant-provisioning', 'provisioning'],
]);

it('refuses an empty or whitespace-only identifier without touching the mapping source', function (string $identifier) {
    $spy = new class implements TenantDatabaseRepository
    {
        public int $lookups = 0;

        public function find(string $tenantId): ?TenantDatabase
        {
            $this->lookups++;

            return null;
        }
    };

    app()->instance(TenantDatabaseRepository::class, $spy);
    app()->forgetScopedInstances();

    $before = tenancyTenantStanza();

    expect(function () use ($identifier): void {
        tenancy()->enter($identifier);
    })
        ->toThrow(InvalidTenantIdentifier::class, 'must be a non-empty string');

    // AC-11: refused before the mapping source is consulted at all.
    expect($spy->lookups)->toBe(0)
        ->and(tenancyTenantStanza())->toBe($before)
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
})->with([
    'empty' => '',
    'spaces' => '   ',
    'tab and newline' => "\t\n",
]);

it('refuses a mapping record that asks for a driver the platform does not allow', function () {
    $before = tenancyTenantStanza();

    expect(function (): void {
        tenancy()->enter('tenant-wrong-driver');
    })
        ->toThrow(TenantConnectionMismatch::class, 'not one of the allowed tenant drivers');

    expect(tenancyTenantStanza())->toBe($before)
        ->and(tenancy()->current()->hasTenant())->toBeFalse();
});

it('nests re-entry for the same tenant and refuses entering a different one', function () {
    tenancy()->enter('tenant-a');

    try {
        tenancy()->enter('tenant-a');

        expect(tenancy()->current()->tenantId())->toBe('tenant-a')
            ->and(tenancyMarker())->toBe('tenant-a');

        expect(function (): void {
            tenancy()->enter('tenant-b');
        })
            ->toThrow(TenantAlreadyResolved::class, 'tenant-b');

        // The inner leave() must not tear the outer scope down.
        tenancy()->leave();

        expect(tenancy()->current()->tenantId())->toBe('tenant-a')
            ->and(tenancyMarker())->toBe('tenant-a');
    } finally {
        tenancy()->leave();
    }

    expect(tenancy()->current()->hasTenant())->toBeFalse()
        ->and(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());
});

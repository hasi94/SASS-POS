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
 * The tenancy fixture helpers this file used to declare at file scope now live
 * in ../Support/tenancy-fixture.php (T-004 FP-164), because two other Feature
 * files call them and could not be run on their own while they lived here.
 */

require_once __DIR__.'/../Support/tenancy-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

// T-004 FP-155 (AC-6): the two shared SQLite files are removed once per file, not
// once per case. Native calls only - afterAll runs outside the application.
afterAll(function () {
    tenancyDeleteFixtureRoot();
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

it('still starts every case from a fixture directory holding only the two databases', function () {
    /*
     * T-004 FP-155 (AC-7). Declared last, so it runs after every case above has
     * had its chance to leave something behind.
     *
     * The fixture files are now built once per file instead of once per case, so
     * the shared state has to be proved rather than assumed: a case that asserts
     * something does NOT yet exist must still observe that. The mapping's
     * `tenant-unopenable` tenant points at no-such-database.sqlite inside this
     * directory and TenancyCredentialLeakTest's refusal cases depend on it being
     * absent - if anything ever created it, those cases would connect
     * successfully and stop proving anything.
     */
    $present = array_map('basename', glob(tenancyFixtureRoot().DIRECTORY_SEPARATOR.'*') ?: []);

    sort($present);

    expect($present)->toBe(['tenant-a.sqlite', 'tenant-b.sqlite'])
        ->and(is_file(tenancyFixtureRoot().DIRECTORY_SEPARATOR.'no-such-database.sqlite'))->toBeFalse();

    // And the shared databases still hold exactly what a freshly built one would.
    expect(tenancy()->run('tenant-a', fn (): string => tenancyMarker()))->toBe('tenant-a')
        ->and(tenancy()->run('tenant-b', fn (): string => tenancyMarker()))->toBe('tenant-b');
});

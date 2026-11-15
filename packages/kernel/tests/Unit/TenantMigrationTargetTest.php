<?php

use Flexipos\Kernel\Tenancy\TenantDatabase;
use Flexipos\Kernel\Tenancy\TenantMigrationTarget;

/*
 * AC-18's control object. TenantDatabaseTest asserts that the serving record
 * carries **no** migrate username and no migrate credential reference; this
 * file asserts the opposite of the runner's own type, which is what makes that
 * absence a decision rather than an omission nobody has noticed yet.
 *
 * No TestCase binding: the record needs no application.
 */

function tenantMigrationTarget(string $status = 'active'): TenantMigrationTarget
{
    return new TenantMigrationTarget(
        tenantId: '01J0000000000000000000000A',
        driver: 'mariadb',
        host: 'db-01',
        port: 3306,
        database: 'flx_t_000123',
        migrateUsername: 'flx_t000123_migrate',
        migrateCredentialRef: 'env://TENANT_000123_MIGRATE_PASSWORD',
        status: $status,
        schemaVersion: 5,
        credentialVersion: 2,
    );
}

it('carries the migrate pair the serving record must not have', function () {
    $target = tenantMigrationTarget();

    expect($target->migrateUsername)->toBe('flx_t000123_migrate')
        ->and($target->migrateCredentialRef)->toBe('env://TENANT_000123_MIGRATE_PASSWORD');

    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(TenantMigrationTarget::class))->getProperties()
    );

    sort($properties);

    expect($properties)->toBe([
        'credentialVersion', 'database', 'driver', 'host', 'migrateCredentialRef',
        'migrateUsername', 'port', 'schemaVersion', 'status', 'tenantId',
    ]);

    // The two names are on this type and on no other: the serving record is
    // asserted separately to have neither, and that pair of assertions is the
    // whole of AC-18.
    $serving = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(TenantDatabase::class))->getProperties()
    );

    expect($serving)->not->toContain('migrateUsername');
    expect($serving)->not->toContain('migrateCredentialRef');
});

it('has no property or constructor parameter that could hold a password', function () {
    // A reference, never a value - the same rule the serving record lives by.
    // `migrateCredentialRef` ends in `_ref`'s camel-case form, which is what
    // marks a reference, and the pattern below allows it deliberately while
    // still refusing anything named like a secret.
    $reflection = new ReflectionClass(TenantMigrationTarget::class);

    $names = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        $reflection->getProperties()
    );

    foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
        $names[] = $parameter->getName();
    }

    foreach ($names as $name) {
        expect(preg_match('/pass(word)?|secret|pwd/i', $name))->toBe(0, "TenantMigrationTarget exposes [{$name}]");
    }
});

it('becomes a connection record that connects as the migrate user', function () {
    $record = tenantMigrationTarget()->asConnectionRecord();

    // The one place the two namings meet, and it is a conversion rather than a
    // shared field: the record a migration connects with carries the migrate
    // pair in the username and credential-reference fields, because that is
    // what a migration must connect as.
    expect($record)->toBeInstanceOf(TenantDatabase::class)
        ->and($record->username)->toBe('flx_t000123_migrate')
        ->and($record->credentialRef)->toBe('env://TENANT_000123_MIGRATE_PASSWORD')
        ->and($record->tenantId)->toBe('01J0000000000000000000000A')
        ->and($record->driver)->toBe('mariadb')
        ->and($record->host)->toBe('db-01')
        ->and($record->port)->toBe(3306)
        ->and($record->database)->toBe('flx_t_000123')
        ->and($record->status)->toBe('active')
        ->and($record->schemaVersion)->toBe(5)
        ->and($record->credentialVersion)->toBe(2);
});

it('is active for exactly the active status, like the serving record', function (string $status, bool $active) {
    // Status rules are not waived by the migration purpose: a tenant that is
    // suspended, moving or restoring is not migrated either (AC-51).
    expect(tenantMigrationTarget($status)->isActive())->toBe($active);
})->with([
    ['active', true],
    ['migrating', false],
    ['suspended', false],
    ['moving', false],
    ['restoring', false],
    ['archived', false],
    ['failed', false],
    ['ACTIVE', false],
    ['', false],
]);

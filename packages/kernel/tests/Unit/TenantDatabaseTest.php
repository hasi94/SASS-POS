<?php

use Flexipos\Kernel\Tenancy\TenantDatabase;

/*
 * AC-10 and AC-24. The status gate is deny-by-default (decision D-26) and the
 * record has nowhere to put a password (AC-8's contract half).
 *
 * No TestCase binding: the DTO needs no application.
 */

function tenantDatabaseWithStatus(string $status): TenantDatabase
{
    return new TenantDatabase(
        tenantId: '01J0000000000000000000000A',
        driver: 'mysql',
        host: 'db-01',
        port: 3306,
        database: 'flx_t_000123',
        username: 'flx_t000123_app',
        credentialRef: 'env://TENANT_000123_APP_PASSWORD',
        status: $status,
    );
}

it('is active for exactly the active status', function () {
    expect(TenantDatabase::STATUS_ACTIVE)->toBe('active')
        ->and(tenantDatabaseWithStatus('active')->isActive())->toBeTrue();
});

it('is not active for any other status docs/database.md enumerates', function (string $status) {
    expect(tenantDatabaseWithStatus($status)->isActive())->toBeFalse();
})->with([
    'provisioning',
    'migrating',
    'read_only',
    'suspended',
    'moving',
    'restoring',
    'archived',
    'failed',
    // Deny by default: an unrecognised or differently cased value is refused
    // rather than guessed at.
    'ACTIVE',
    'Active',
    'active ',
    'unheard-of',
    '',
]);

it('exposes every field the connection plumbing needs and nothing operational', function () {
    $record = tenantDatabaseWithStatus('active');

    expect($record->tenantId)->toBe('01J0000000000000000000000A')
        ->and($record->driver)->toBe('mysql')
        ->and($record->host)->toBe('db-01')
        ->and($record->port)->toBe(3306)
        ->and($record->database)->toBe('flx_t_000123')
        ->and($record->username)->toBe('flx_t000123_app')
        ->and($record->credentialRef)->toBe('env://TENANT_000123_APP_PASSWORD')
        ->and($record->status)->toBe('active');

    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(TenantDatabase::class))->getProperties()
    );

    sort($properties);

    expect($properties)->toBe([
        'credentialRef', 'database', 'driver', 'host', 'port', 'status', 'tenantId', 'username',
    ]);
});

it('has no property or constructor parameter that could hold a password', function () {
    $reflection = new ReflectionClass(TenantDatabase::class);

    $names = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        $reflection->getProperties()
    );

    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        $names[] = $parameter->getName();
    }

    foreach ($names as $name) {
        expect(preg_match('/pass(word)?|secret|pwd/i', $name))->toBe(0, "TenantDatabase exposes [{$name}]");
    }
});

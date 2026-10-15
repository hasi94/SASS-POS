<?php

use Flexipos\Kernel\Schema\Exceptions\SchemaRepairRefused;
use Flexipos\Kernel\Schema\Features\RepairSchemaVersion\RepairSchemaVersion;
use Flexipos\Kernel\Schema\Features\RepairSchemaVersion\RepairSchemaVersionHandler;
use Flexipos\Kernel\Schema\Features\RepairSchemaVersion\RepairSchemaVersionResult;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Correcting a mirror, and refusing the direction code cannot correct
|--------------------------------------------------------------------------
|
| T-008 step 12, AC-54 and AC-55's serving half. Red by omitting the
| mechanism; nothing here issues a destructive statement.
|
| **The input AC-54 insists on** (plan section 7's mutation notes): the
| tenant's own `migrations` table must already show the target. Without that,
| the repair path and the ordinary migrate path are indistinguishable - both
| end with the mapping at the right number - and the assertion would not be
| about a repair at all. So each case writes the tenant's own table directly
| and the repair is asserted to leave it byte-for-byte alone.
|
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();
    migrateFixtureServer();
});

afterEach(function () {
    migrateFixtureTearDown();
});

/**
 * @param  list<string>|null  $tenantIds
 */
function repairAll(?array $tenantIds = null): RepairSchemaVersionResult
{
    return app(RepairSchemaVersionHandler::class)->handle(
        new RepairSchemaVersion(runFixtureSet(), $tenantIds)
    );
}

/*
|--------------------------------------------------------------------------
| AC-54 - the crash between two writes
|--------------------------------------------------------------------------
*/

it('corrects a mapping behind its own database and re-applies nothing', function () {
    migrateFixtureTenant('tenant-behind', migrateFixtureId('server-one'), ['schema_version' => 4]);
    migrateFixtureApplyLog('tenant-behind', 6);

    $before = migrateFixtureApplyLogOf('tenant-behind');

    expect($before)->toHaveCount(6);

    $result = repairAll();

    expect($result->corrected)->toBe([migrateFixtureId('tenant-behind') => 6])
        ->and((int) migrateFixtureMappingRow(migrateFixtureId('tenant-behind'))['schema_version'])->toBe(6);

    // Row counts **and** batch numbers, not "the table still exists": a
    // re-applied migration appears under a second batch, and a repair that
    // ran the migrator would be invisible to a count alone.
    expect(migrateFixtureApplyLogOf('tenant-behind'))->toBe($before)
        ->and(array_unique(array_values(migrateFixtureApplyLogOf('tenant-behind'))))->toBe([1]);
});

it('leaves a mapping that already matches alone, and says it looked', function () {
    // The control. "Nothing was corrected" is also what a repair pointed at
    // an empty set reports, so the aligned list is what proves it looked.
    migrateFixtureTenant('tenant-level', migrateFixtureId('server-one'), ['schema_version' => 6]);
    migrateFixtureApplyLog('tenant-level', 6);

    $result = repairAll();

    expect($result->corrected)->toBe([])
        ->and($result->aligned)->toBe([migrateFixtureId('tenant-level')])
        ->and((int) migrateFixtureMappingRow(migrateFixtureId('tenant-level'))['schema_version'])->toBe(6);
});

it('evicts the cached mapping it corrected', function () {
    migrateFixtureTenant('tenant-behind', migrateFixtureId('server-one'), ['schema_version' => 4]);
    migrateFixtureApplyLog('tenant-behind', 6);

    // Warm the cache first, which is the only input at which an eviction is
    // distinguishable from a reader that never cached.
    expect(app(TenantDatabaseRepository::class)->find(migrateFixtureId('tenant-behind'))?->schemaVersion)->toBe(4);

    repairAll();

    expect(app(TenantDatabaseRepository::class)->find(migrateFixtureId('tenant-behind'))?->schemaVersion)->toBe(6);
});

/*
|--------------------------------------------------------------------------
| AC-55 - the direction code must not correct
|--------------------------------------------------------------------------
*/

it('refuses a mapping ahead of its database and stops the tenant serving first', function () {
    migrateFixtureTenant('tenant-ahead', migrateFixtureId('server-one'), ['schema_version' => 6]);
    migrateFixtureApplyLog('tenant-ahead', 3);

    $before = migrateFixtureApplyLogOf('tenant-ahead');

    expect(fn () => repairAll())->toThrow(SchemaRepairRefused::class, 'the mirror is ahead of the tenant');

    $mapping = migrateFixtureMappingRow(migrateFixtureId('tenant-ahead'));

    // The write happened **before** the throw, which is the point: a refusal
    // that only threw would leave this tenant serving for as long as nobody
    // read the exception. `failed` is not an active status, so
    // Tenancy::enter() refuses it - AC-55's "refused for serving until
    // corrected", structurally.
    expect((string) $mapping['status'])->toBe('failed')
        ->and((string) $mapping['status_reason'])->toContain('claims schema version 6')
        // And the mirror is **not** lowered: lowering it is the correction
        // that would make a half-restored database look healthy.
        ->and((int) $mapping['schema_version'])->toBe(6)
        ->and(migrateFixtureApplyLogOf('tenant-ahead'))->toBe($before);
});

it('does not stop a tenant serving merely for being behind', function () {
    // The control for the case above: the status write belongs to the ahead
    // direction only. A repair that suspended everything it touched would
    // pass the assertions there and take the platform down here.
    migrateFixtureTenant('tenant-behind', migrateFixtureId('server-one'), ['schema_version' => 4]);
    migrateFixtureApplyLog('tenant-behind', 6);

    repairAll();

    expect((string) migrateFixtureMappingRow(migrateFixtureId('tenant-behind'))['status'])->toBe('active');
});

it('narrows to the tenants it was given', function () {
    migrateFixtureTenant('tenant-behind', migrateFixtureId('server-one'), ['schema_version' => 4]);
    migrateFixtureApplyLog('tenant-behind', 6);

    // An ahead tenant that would refuse the whole repair if it were in scope.
    migrateFixtureTenant('tenant-ahead', migrateFixtureId('server-one'), ['schema_version' => 6]);
    migrateFixtureApplyLog('tenant-ahead', 3);

    $result = repairAll([migrateFixtureId('tenant-behind')]);

    expect($result->corrected)->toBe([migrateFixtureId('tenant-behind') => 6])
        // Untouched, because it was out of scope - not because the refusal
        // was skipped.
        ->and((string) migrateFixtureMappingRow(migrateFixtureId('tenant-ahead'))['status'])->toBe('active');
});

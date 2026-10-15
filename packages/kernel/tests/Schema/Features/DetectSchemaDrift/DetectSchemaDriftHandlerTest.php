<?php

use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\DetectSchemaDrift;
use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\DetectSchemaDriftHandler;
use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\SchemaDrift;
use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Does the mirror still match the database
|--------------------------------------------------------------------------
|
| T-008 step 12, AC-55. Red by omitting the mechanism: there is no
| destructive statement anywhere in this slice and the observables are a row
| count in the tenant's own `migrations` table and a column in
| `tenant_databases`.
|
| The three tenants are real SQLite files whose own `migrations` tables are
| written directly by the fixture - that disagreement with the mapping **is**
| the input, and a fixture that produced it by running the migrator could not
| produce the `ahead` direction at all.
|
| `[gated]` only for the engine's own reads; the comparison itself needs no
| engine and is proven here while ENGINE_HOLD is set. AC-55 stays NOT
| VERIFIED, because the criterion is marked [E].
|
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();
});

afterEach(function () {
    migrateFixtureTearDown();
});

/**
 * @param  list<string>|null  $tenantIds
 * @return list<SchemaDrift>
 */
function driftReport(?array $tenantIds = null): array
{
    return app(DetectSchemaDriftHandler::class)->handle(new DetectSchemaDrift(runFixtureSet(), $tenantIds));
}

/**
 * The one answer for one tenant.
 *
 * @param  list<string>|null  $tenantIds
 */
function driftFor(string $stem, ?array $tenantIds = null): SchemaDrift
{
    foreach (driftReport($tenantIds) as $answer) {
        if ($answer->tenantId === migrateFixtureId($stem)) {
            return $answer;
        }
    }

    throw new RuntimeException('no drift answer for '.$stem);
}

/*
|--------------------------------------------------------------------------
| Both directions, and the control between them
|--------------------------------------------------------------------------
*/

it('reports a mapping behind its database, one ahead of it, and not the one that matches', function () {
    $server = migrateFixtureServer();

    // Behind: the database carries six, the mirror still says four - the
    // crash between the runner's two writes.
    migrateFixtureTenant('tenant-behind', $server, ['schema_version' => 4]);
    migrateFixtureApplyLog('tenant-behind', 6);

    // Ahead: the mirror claims six and the database has three, which is what
    // a restore from an old backup looks like.
    migrateFixtureTenant('tenant-ahead', $server, ['schema_version' => 6]);
    migrateFixtureApplyLog('tenant-ahead', 3);

    // The control. Without a tenant that matches, "the drifted ones are
    // reported" is also true of a check that reports everybody.
    migrateFixtureTenant('tenant-level', $server, ['schema_version' => 6]);
    migrateFixtureApplyLog('tenant-level', 6);

    $behind = driftFor('tenant-behind');
    $ahead = driftFor('tenant-ahead');
    $level = driftFor('tenant-level');

    expect($behind->direction)->toBe(SchemaDrift::BEHIND)
        ->and($behind->mappingVersion)->toBe(4)
        ->and($behind->appliedVersion)->toBe(6)
        ->and($behind->drifted())->toBeTrue();

    expect($ahead->direction)->toBe(SchemaDrift::AHEAD)
        ->and($ahead->mappingVersion)->toBe(6)
        ->and($ahead->appliedVersion)->toBe(3)
        ->and($ahead->drifted())->toBeTrue();

    expect($level->direction)->toBe(SchemaDrift::ALIGNED)
        ->and($level->drifted())->toBeFalse();
});

it('reads a tenant that has never been migrated as version zero rather than as drift-free', function () {
    // The empty tenant: no `migrations` table at all. A reader that treated a
    // missing table as "nothing to compare" would report it aligned, and the
    // one tenant a run most needs to find would be invisible.
    $server = migrateFixtureServer();

    migrateFixtureTenant('tenant-empty', $server, ['schema_version' => 6]);

    $answer = driftFor('tenant-empty');

    expect($answer->direction)->toBe(SchemaDrift::AHEAD)
        ->and($answer->appliedVersion)->toBe(0);
});

it('writes nothing at all', function () {
    $server = migrateFixtureServer();

    migrateFixtureTenant('tenant-behind', $server, ['schema_version' => 4]);
    migrateFixtureApplyLog('tenant-behind', 6);

    $before = migrateFixtureApplyLogOf('tenant-behind');

    $touched = runFixtureConnectionsUsedBy(static fn () => driftReport());

    foreach ($touched['sql'] as $sql) {
        // A query, and the word is load-bearing: a report that corrected what
        // it found would be a migration nobody asked for.
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }

    expect((int) migrateFixtureMappingRow(migrateFixtureId('tenant-behind'))['schema_version'])->toBe(4)
        ->and(migrateFixtureApplyLogOf('tenant-behind'))->toBe($before);
});

it('narrows to the tenants it was given', function () {
    $server = migrateFixtureServer();

    migrateFixtureTenant('tenant-behind', $server, ['schema_version' => 4]);
    migrateFixtureApplyLog('tenant-behind', 6);
    migrateFixtureTenant('tenant-level', $server, ['schema_version' => 6]);
    migrateFixtureApplyLog('tenant-level', 6);

    $report = driftReport([migrateFixtureId('tenant-behind')]);

    expect($report)->toHaveCount(1)
        ->and($report[0]->tenantId)->toBe(migrateFixtureId('tenant-behind'));
});

it('reports an unopenable tenant without a credential and keeps going', function () {
    $server = migrateFixtureServer();

    // A mapping pointing at a directory that is not a database: the shape of
    // a tenant whose server is unreachable, without needing one.
    migrateFixtureTenant('tenant-broken', $server, [
        'schema_version' => 6,
        'database_name' => migrateFixtureRoot().DIRECTORY_SEPARATOR.'no-such-directory'.DIRECTORY_SEPARATOR.'x.sqlite',
    ]);
    migrateFixtureTenant('tenant-level', $server, ['schema_version' => 6]);
    migrateFixtureApplyLog('tenant-level', 6);

    $broken = driftFor('tenant-broken');

    expect($broken->direction)->toBe(SchemaDrift::UNREACHABLE)
        ->and((string) $broken->reason)->not->toContain(migrateFixturePassword())
        ->and((string) $broken->reason)->not->toContain(migrateFixtureCredentialRef());

    // The fleet-wide scan is not stopped by one tenant: the other answer is
    // still there, which is the half a single-tenant case cannot show.
    expect(driftFor('tenant-level')->direction)->toBe(SchemaDrift::ALIGNED);
});

it('leaves no tenant resolved behind it', function () {
    $server = migrateFixtureServer();

    migrateFixtureTenant('tenant-level', $server, ['schema_version' => 6]);
    migrateFixtureApplyLog('tenant-level', 6);

    driftReport();

    expect(static fn () => DB::connection('tenant')->select('select 1'))
        ->toThrow(NoTenantResolved::class);
});

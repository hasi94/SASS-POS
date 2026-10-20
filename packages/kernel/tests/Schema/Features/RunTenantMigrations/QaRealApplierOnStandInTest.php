<?php

use Flexipos\Kernel\Schema\Features\MigrateTenant\ArtisanTenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\MigrateTenant\TenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrations;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA, T-008: the shipped applier, run for real, on the stand-in
|--------------------------------------------------------------------------
|
| Every handler test in this branch replaces the migrator with a fake behind
| the TenantMigrationApplier seam, and the only tests that bind the shipped
| ArtisanTenantMigrationApplier are the gated engine ones, which are skipped
| under ENGINE_HOLD. So the one class that actually calls `migrate` on a
| tenant has never executed in this branch.
|
| This file executes it. Three tenants are real SQLite files, the run goes
| through the whole shipped graph (plan, claim, enter on the migrating
| purpose, the framework's migrator one file at a time, mirror, close), and
| the observables are the ones AC-38, AC-43 and AC-46 name, read from the
| tenants' own `migrations` tables and the real tables the baseline creates.
|
| **This is a stand-in half and is not evidence for any [E] criterion.** SQLite
| is not the engine (the sentinel columns take their SQLite form, no collation
| is checked, nothing is refused by a server), AC-38, AC-43 and AC-46 are [E]
| and stay NOT VERIFIED behind ENGINE_HOLD (G-1). What this does answer is
| whether the applier, run at all, does what its docblock says.
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();

    // The fake that migrateFixtureBoot() installs is exactly what this file
    // exists not to use.
    app()->bind(TenantMigrationApplier::class, ArtisanTenantMigrationApplier::class);

    $server = migrateFixtureServer();

    runFixturePublish(runFixtureSet(1));

    migrateFixtureTenant('tenant-a', $server, ['size_bytes' => 1024]);
    migrateFixtureTenant('tenant-b', $server, ['size_bytes' => 2048]);
});

afterEach(function () {
    migrateFixtureTearDown();
});

/**
 * The tables one tenant database holds, read with a bare PDO.
 *
 * @return list<string>
 */
function qaTenantTables(string $stem): array
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));
    $rows = $pdo->query("select name from sqlite_master where type = 'table'");
    $names = [];

    foreach ($rows === false ? [] : $rows->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (! str_starts_with((string) $name, 'sqlite_')) {
            $names[] = (string) $name;
        }
    }

    sort($names);

    return $names;
}

it('applies the baseline and a later migration to every tenant with the shipped applier, and nothing twice', function () {
    $run = static fn () => app(RunTenantMigrationsHandler::class)->handle(
        new RunTenantMigrations(set: runFixtureSet(1), workerId: 'qa-worker')
    );

    $result = $run();

    expect($result->status)->toBe('completed')
        ->and($result->exitCode)->toBe(0);

    foreach (['tenant-a', 'tenant-b'] as $stem) {
        $log = migrateFixtureApplyLogOf($stem);

        // The tenant's own table lists every shipped file plus FX-1, and the
        // real tables exist: the table list is the observable the fake
        // applier cannot produce. Both sides are derived from the tree
        // (T-010 step 3): the count from tenantMigrationFileCount(), the
        // names from tenantTreeTables() plus Laravel's `migrations` and
        // FX-1's own table, so a ninth shipped migration does not edit this
        // file again.
        $expectedTables = array_merge(tenantTreeTables(), ['fx_projection_counts', 'migrations']);
        sort($expectedTables);

        expect(array_keys($log))->toHaveCount(tenantMigrationFileCount() + 1)
            ->and(qaTenantTables($stem))->toBe($expectedTables)
            ->and((int) migrateFixtureMappingRow(migrateFixtureId($stem))['schema_version'])->toBe(tenantMigrationFileCount() + 1);
    }

    // A second bulk run selects tenants below the target; both are at it, so
    // there is nothing to plan and both logs must be, row for row and batch
    // for batch, exactly what they were (AC-46's observable).
    $before = [migrateFixtureApplyLogOf('tenant-a'), migrateFixtureApplyLogOf('tenant-b')];

    expect($run()->tenantIds())->toBe([])
        ->and([migrateFixtureApplyLogOf('tenant-a'), migrateFixtureApplyLogOf('tenant-b')])->toBe($before);
});

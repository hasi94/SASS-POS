<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Exceptions\MigrationSetRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-011: the fixture baseline the planner corpus composes onto (FP-304)
|--------------------------------------------------------------------------
|
| AC-2 and AC-3: the fixture baseline is six synthetic expand files numbered
| so that file n is version n, nothing in it is a product file, and
| runFixtureSet() composes the FX directories onto it by position.
|
| This file never reads the shipped tenant tree as a planner input: the
| product side of "no file copied" is read from the committed catalogue
| fixture, which holds the shipped checksums, and the product's table list
| from tenantTreeTables(). ShippedTenantTreeTest.php is the one kernel file
| that reads the shipped tree.
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

/**
 * Every defect a candidate fixture baseline has, as readable lines.
 *
 * A pure function over catalogue rows, so each control is an in-memory input
 * and never a file planted in the tree.
 *
 * @param  list<array{version: int, migration: string, phase: string, checksum: string, is_destructive: bool, requires_offline: bool}>  $rows
 * @param  list<string>  $productChecksums
 * @return list<string>
 */
function fixtureBaselineDefects(array $rows, array $productChecksums): array
{
    if ($rows === []) {
        return ['the fixture baseline is empty'];
    }

    $defects = [];

    foreach ($rows as $row) {
        if ($row['phase'] !== 'expand') {
            $defects[] = $row['migration'].' is '.$row['phase'].', not expand';
        }

        if ($row['is_destructive']) {
            $defects[] = $row['migration'].' is destructive';
        }

        $sequence = preg_match('/^\d{4}_\d{2}_\d{2}_(\d{6})_/', $row['migration'], $match) === 1 ? (int) $match[1] : null;

        if ($sequence !== $row['version']) {
            $defects[] = $row['migration'].' has sequence '.var_export($sequence, true).' at version '.$row['version'];
        }

        if (in_array($row['checksum'], $productChecksums, true)) {
            $defects[] = $row['migration'].' carries a product file checksum';
        }
    }

    return $defects;
}

/**
 * The checksums of every shipped tenant migration, read from the committed
 * catalogue fixture rather than from the tree.
 *
 * @return list<string>
 */
function fixtureBaselineProductChecksums(): array
{
    $rows = MigrationCatalogue::decodeFixture((string) file_get_contents(base_path((string) config('schema.catalogue_fixture'))));

    return array_column($rows, 'checksum');
}

/**
 * @return list<array{version: int, migration: string, phase: string, checksum: string, is_destructive: bool, requires_offline: bool}>
 */
function fixtureBaselineRows(MigrationSet $set): array
{
    return array_map(static fn (MigrationCatalogue $entry): array => $entry->toRow(), $set->catalogue());
}

it('is six synthetic expand files numbered so that file n is version n (AC-2)', function () {
    $rows = fixtureBaselineRows(runFixtureSet());
    $productChecksums = fixtureBaselineProductChecksums();

    fwrite(STDOUT, PHP_EOL.'AC-2 fixture baseline: '.count($rows).' entries'.PHP_EOL);

    foreach ($rows as $row) {
        fwrite(STDOUT, '  '.$row['version'].' '.$row['migration'].' '.$row['phase'].PHP_EOL);
    }

    expect($rows)->not->toBe([])
        ->and($productChecksums)->not->toBe([])
        ->and(fixtureBaselineDefects($rows, $productChecksums))->toBe([])
        ->and(array_column($rows, 'version'))->toBe(range(1, runFixtureBaselineVersion()))
        ->and(array_values(array_intersect(runFixtureBaselineTables(), tenantTreeTables())))->toBe([])
        ->and(runFixtureBaselineTables())->toHaveCount(runFixtureBaselineVersion());
});

it('reports a contract entry, a numbering gap and a product checksum (AC-2 controls)', function () {
    $rows = fixtureBaselineRows(runFixtureSet());
    $productChecksums = fixtureBaselineProductChecksums();

    $contract = $rows;
    $contract[1]['phase'] = 'contract';

    // A gap: the file called "3" sits at version 2, which is what a baseline
    // missing its second file looks like once MigrationSet numbers it.
    $gap = [$rows[0], $rows[2]];
    $gap[1]['version'] = 2;

    $copied = $rows;
    $copied[3]['checksum'] = $productChecksums[0];

    expect(fixtureBaselineDefects($contract, $productChecksums))->toBe([$rows[1]['migration'].' is contract, not expand'])
        ->and(fixtureBaselineDefects($gap, $productChecksums))->toBe([$rows[2]['migration'].' has sequence 3 at version 2'])
        ->and(fixtureBaselineDefects($copied, $productChecksums))->toBe([$rows[3]['migration'].' carries a product file checksum'])
        ->and(fixtureBaselineDefects([], $productChecksums))->toBe(['the fixture baseline is empty']);
});

it('composes the FX directories onto the fixture baseline by position (AC-3)', function () {
    $baseline = runFixtureBaselineVersion();

    expect(fixtureBaselineRows(runFixtureSet()))->toBe(fixtureBaselineRows(MigrationSet::baseline(runFixtureBaselineDirectory())));

    $set = runFixtureSet(1, 4);
    $fx1 = MigrationSet::named('fx1', base_path('tests/Fixtures/TenantMigrations/FX1'))->catalogue()[0]->migration;
    $fx4 = MigrationSet::named('fx4', base_path('tests/Fixtures/TenantMigrations/FX4'))->catalogue()[0]->migration;

    expect(array_slice(fixtureBaselineRows($set), 0, $baseline))->toBe(fixtureBaselineRows(runFixtureSet()))
        ->and($set->entryFor($baseline + 1)?->migration)->toBe($fx1)
        ->and($set->entryFor($baseline + 2)?->migration)->toBe($fx4)
        ->and($set->highestVersion())->toBe($baseline + 2)
        ->and($set->name)->toBe('baseline+fx1+fx4');

    // Control: positional, not by name - the other order is the other order.
    $reversed = runFixtureSet(4, 1);

    expect($reversed->entryFor($baseline + 1)?->migration)->toBe($fx4)
        ->and($reversed->entryFor($baseline + 2)?->migration)->toBe($fx1);
});

it('refuses a set built from the fixture baseline and an FX directory that does not exist (AC-3 control)', function () {
    expect(fn () => runFixtureSet(99))->toThrow(MigrationSetRefused::class);
});

/**
 * Plan a set through the handler, with the connection instrument around it.
 *
 * @return array{result: mixed, thrown: Throwable|null, queried: list<string>, resolved: list<string>, sql: list<string>}
 */
function fixtureBaselinePlan(MigrationSet $set): array
{
    return runFixtureConnectionsUsedBy(static fn () => app(PlanMigrationRunHandler::class)->handle(
        new PlanMigrationRun(set: $set)
    ));
}

/*
|--------------------------------------------------------------------------
| AC-5 and AC-6 - the corpus is alive again, and the guards fire for their
| own reasons
|--------------------------------------------------------------------------
*/

it('plans FX-1 for a fleet at 0, every job from 0 to B + 1 (AC-5, control b)', function () {
    masterMappingMigrate();

    $set = runFixtureSet(1);
    runFixturePublish($set);

    $server = masterMappingServer('alive-server');
    $one = runFixtureTenant('alive-one', $server, 'active', 0, 10);
    $two = runFixtureTenant('alive-two', $server, 'read_only', 0, 20);

    $touched = fixtureBaselinePlan($set);
    $jobs = runFixtureJobRows();

    expect($touched['thrown'])->toBeNull()
        ->and(array_map(static fn (array $job): string => (string) $job['tenant_id'], $jobs))->toBe([$one, $two])
        ->and(array_values(array_unique(array_map(static fn (array $job): int => (int) $job['from_version'], $jobs))))->toBe([0])
        ->and(array_values(array_unique(array_map(static fn (array $job): int => (int) $job['to_version'], $jobs))))->toBe([runFixtureBaselineVersion() + 1])
        ->and($set->entryFor(runFixtureBaselineVersion() + 1)?->migration)->toContain('fx1_');
});

it('refuses FX-4 for a tenant below B, naming FX-4, its version and the tenant, and touches nothing (AC-6)', function () {
    masterMappingMigrate();
    config()->set('schema.backups.available', false);

    $set = runFixtureSet(4);
    runFixturePublish($set);

    $server = masterMappingServer('guard-server');
    $behind = runFixtureTenant('guard-behind', $server, 'active', 0, 10);
    runFixtureTenant('guard-ready', $server, 'active', runFixtureBaselineVersion(), 20);

    $touched = fixtureBaselinePlan($set);
    $message = (string) $touched['thrown']?->getMessage();
    $fx4 = (string) $set->entryFor(runFixtureBaselineVersion() + 1)?->migration;

    preg_match_all('/\d{4}_\d{2}_\d{2}_\d{6}_\w+/', $message, $names);

    expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
        ->and($fx4)->toContain('fx4_')
        ->and($message)->toContain('['.$fx4.'] (version '.(runFixtureBaselineVersion() + 1).')')
        ->and($message)->toContain($behind)
        // Exactly one catalogue-shaped name, and it is FX-4's: the refusal names
        // no product file, shown without reading the product tree.
        ->and($names[0])->toBe([$fx4])
        ->and(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([])
        ->and($touched['queried'])->toBe(['master'])
        ->and($touched['resolved'])->not->toContain('tenant');
});

it('plans FX-4 once every tenant is at B, or only provisioning tenants are behind (AC-6 positive controls)', function () {
    masterMappingMigrate();
    config()->set('schema.backups.available', false);

    $set = runFixtureSet(4);
    runFixturePublish($set);

    $server = masterMappingServer('guard-server');
    $one = runFixtureTenant('guard-one', $server, 'active', runFixtureBaselineVersion(), 10);
    runFixtureTenant('guard-new', $server, 'provisioning', 0, 20);

    $touched = fixtureBaselinePlan($set);

    expect($touched['thrown'])->toBeNull()
        ->and(array_map(static fn (array $job): int => (int) $job['to_version'], runFixtureJobRows()))->toContain(runFixtureBaselineVersion() + 1)
        ->and(array_map(static fn (array $job): string => (string) $job['tenant_id'], runFixtureJobRows()))->toContain($one);
});

it('refuses FX-5 destructively for a fleet at B + 1, and does not refuse FX-4 there (AC-6)', function () {
    masterMappingMigrate();
    config()->set('schema.backups.available', false);

    // The fleet carries FX-1 and not what follows it, so the contract guard has
    // nothing to say and only the destructive refusal is left to fire.
    $set = runFixtureSet(1, 5);
    runFixturePublish($set);

    $server = masterMappingServer('destroy-server');
    runFixtureTenant('destroy-one', $server, 'active', runFixtureBaselineVersion() + 1, 10);

    $touched = fixtureBaselinePlan($set);

    expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
        ->and((string) $touched['thrown']?->getMessage())->toContain('schema.backups.available')
        ->and(runFixtureJobRows())->toBe([]);
});

it('plans FX-4, contract but not destructive, for the same fleet at B + 1 (AC-6 control)', function () {
    masterMappingMigrate();
    config()->set('schema.backups.available', false);

    $set = runFixtureSet(1, 4);
    runFixturePublish($set);

    $server = masterMappingServer('destroy-server');
    $tenant = runFixtureTenant('destroy-one', $server, 'active', runFixtureBaselineVersion() + 1, 10);

    $touched = fixtureBaselinePlan($set);

    expect($touched['thrown'])->toBeNull()
        ->and(array_map(static fn (array $job): string => (string) $job['tenant_id'], runFixtureJobRows()))->toBe([$tenant]);
});

<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA, T-008, AC-58: the named half
|--------------------------------------------------------------------------
|
| AC-58 says "a run (bulk or naming one tenant)" and "a tenant left out of a
| named run still blocks (D-46)". The shipped cases are bulk runs only, so
| nothing in them distinguishes a guard that reads the fleet from one that
| reads the run's own selection. The dataset here has exactly one tenant that
| is ready and one that is not, and names the ready one: a selection-scoped
| guard sees nothing wrong and plans, a fleet-scoped guard refuses and names
| the tenant that was left out.
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();

    config()->set('schema.backups.available', false);
});

it('refuses a named run for a ready tenant while a tenant it left out is still behind', function () {
    $set = runFixtureSet(4);
    runFixturePublish($set);

    $server = masterMappingServer('named-server');
    $ready = runFixtureTenant('named-ready', $server, 'active', tenantMigrationFileCount(), 10);
    $left = runFixtureTenant('named-left-out', $server, 'active', 0, 20);

    $thrown = null;

    try {
        app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set, tenantIds: [$ready]));
    } catch (MigrationRunRefused $refused) {
        $thrown = $refused;
    }

    expect($thrown)->toBeInstanceOf(MigrationRunRefused::class)
        // The tenant the run did not name is the one that holds it up.
        ->and($thrown->getMessage())->toContain($left)
        ->and($thrown->getMessage())->not->toContain($ready)
        ->and(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([]);
});

it('plans the same named run once the tenant that was left out has caught up', function () {
    // The control: same run, same names, the other tenant at the version
    // before the contract step. Without it the refusal above is also what a
    // guard that refuses every named run would produce.
    $set = runFixtureSet(4);
    runFixturePublish($set);

    $server = masterMappingServer('named-server');
    $ready = runFixtureTenant('named-ready', $server, 'active', tenantMigrationFileCount(), 10);
    runFixtureTenant('named-caught-up', $server, 'active', tenantMigrationFileCount(), 20);

    $result = app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set, tenantIds: [$ready]));

    expect($result->tenantIds())->toBe([$ready]);
});

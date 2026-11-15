<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Kernel\Tenancy\TenantStatusPolicy;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-008 step 13c, AC-58 and D-46: who blocks a contract step
|--------------------------------------------------------------------------
|
| [FIX] BUG-7. The guard used to answer the **blocking** question with the
| **selection** predicate - `whereIn('status', BULK_STATUSES)` - and the two
| questions are opposites. Selection asks "whom may I touch" and is rightly
| an allow list. Blocking asks "whose existence makes this contract step
| unsafe", and the answer is every database that will ever run it: a
| suspended shop comes back, an archived one is restored, a `moving` one
| lands somewhere. A contract step that dropped a column while six statuses
| looked away is the failure D-46 exists to prevent, and it is unrecoverable
| in the way this project's worst failures are (plan 16.2).
|
| So the blocking read excludes exactly one value, `provisioning`, and an
| **unrecognised** status blocks.
|
| Red before the fix: every row below but `active` and `read_only` planned
| happily. No engine and no fixture database - the master is the suite's
| in-memory SQLite and nothing here opens a tenant connection, because the
| guard fires at plan time (AC-39's order).
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();

    config()->set('schema.backups.available', false);
});

/**
 * One ready tenant at the version before the contract step, one straggler at
 * 0 in the status under test. Returns the straggler's identifier.
 */
function blockingFixture(string $status): string
{
    $server = masterMappingServer('blocking-server');

    runFixtureTenant('blocking-ready', $server, 'active', runFixtureBaselineVersion(), 10);

    return runFixtureTenant('blocking-other', $server, $status, 0, 20);
}

it('refuses a contract step while a tenant in any status but provisioning is behind, and names it', function (string $status) {
    $set = runFixtureSet(4);
    runFixturePublish($set);

    $straggler = blockingFixture($status);

    $thrown = null;

    try {
        app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set));
    } catch (MigrationRunRefused $refused) {
        $thrown = $refused;
    }

    expect($thrown)->toBeInstanceOf(MigrationRunRefused::class)
        // Named, because an operator who cannot see which database holds the
        // step up has been told nothing actionable.
        ->and($thrown?->getMessage())->toContain($straggler)
        ->and(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([]);
})->with([
    // The two the selection predicate already saw; they are here so the set
    // below is a superset of the old behaviour rather than a replacement.
    'active' => ['active'],
    'read_only' => ['read_only'],
    // The six the selection predicate was blind to - this is BUG-7.
    'suspended' => ['suspended'],
    'failed' => ['failed'],
    'migrating' => ['migrating'],
    'moving' => ['moving'],
    'restoring' => ['restoring'],
    'archived' => ['archived'],
    // Deny by default: a status this code has never heard of blocks. A
    // future status added to the master and nowhere else must not silently
    // become invisible to the contract guard.
    'an unrecognised status' => ['something-nobody-has-shipped-yet'],
]);

it('plans the contract step when the only tenant behind is provisioning', function () {
    // The control, and it is the whole of the exclusion: without it the
    // refusals above are also what a guard that refuses every contract step
    // would produce. A `provisioning` database holds nothing yet, so a
    // column it never had cannot be dropped out from under it.
    $set = runFixtureSet(4);
    runFixturePublish($set);

    blockingFixture('provisioning');

    $result = app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set));

    expect($result->targetVersion)->toBe(runFixtureBaselineVersion() + 1)
        ->and(runFixtureRunRows())->toHaveCount(1);
});

it('keeps the blocking set and the selection set as two different rules', function () {
    // Stated so a later tidy-up cannot undo it (plan 16.2). The day somebody
    // "unifies" these two lists the contract guard silently stops seeing six
    // statuses again, so the difference itself is asserted: `suspended` is a
    // status the planner never selects and the contract guard always sees.
    $set = runFixtureSet(4);
    runFixturePublish($set);

    $suspended = blockingFixture('suspended');

    $policy = app(TenantStatusPolicy::class);

    expect($policy->bulk())->not->toContain('suspended')
        ->and($policy->accepted(TenantConnectionPurpose::Migrating))->not->toContain('suspended');

    // Not selected: a bulk run at a version it is below plans nothing for it.
    $result = app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: runFixtureSet(), targetVersion: runFixtureBaselineVersion()));

    expect(array_column(runFixtureJobRows(), 'tenant_id'))->not->toContain($suspended)
        ->and($result->targetVersion)->toBe(runFixtureBaselineVersion());

    // Still blocking: the same tenant, the same status, the opposite answer.
    expect(fn () => app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set)))
        ->toThrow(MigrationRunRefused::class, $suspended);
});

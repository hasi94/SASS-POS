<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Testing\Engine\ThreeTenantFixture;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The plan is written before anything is touched
|--------------------------------------------------------------------------
|
| T-008 step 9: AC-39, AC-40, AC-51, AC-58, AC-59 and the refusal half of
| AC-32.
|
| **Every refusal here is proven red by omitting the mechanism, never by
| letting the dangerous fixture run** (plan section 11.5). FX-5 is destructive;
| the way to show the refusal works is to assert that *nothing was written and
| no tenant connection was opened*, which fails the moment the branch is
| removed - not to point a working migrator at a tenant database and see what
| survives. FX-5 is never applied by this file, or by any other.
|
| The mask question of plan section 7 was asked of each refusal before it was
| written, because an absence that something else refuses is not evidence:
|
| - the destructive refusal pins MigrationRunRefused and the **message naming
|   the configuration key** `schema.backups.available`. No engine, no key and
|   no framework error can produce that string: it exists only in the branch
|   this test is about. With the branch removed the plan is written, and the
|   assertion that fails is "zero job rows", which nothing else in the stack
|   would have prevented - there is no constraint against planning a run;
| - the contract guard pins the message naming the blocking tenant, which only
|   the guard knows, because the only other party that could object - a foreign
|   key onto a table FX-1 creates - lives in the tenant database and is never
|   reached at plan time;
| - the status refusal pins the status string, which a selector that merely
|   filtered would never say.
|
| Nothing in this file connects to any engine. The only database is the suite
| in-memory SQLite master, asserted before anything migrates, and every case
| measures the connections the handler used.
|
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();

    // Decision D-10 ships false; a test that relied on the shipped value
    // without saying so would pass for a reason it had not stated.
    config()->set('schema.backups.available', false);
});

/**
 * Plan a run and report what it touched.
 *
 * Every case goes through this, so every case measures the connections as well
 * as the rows: "no tenant database was touched" is the criterion, not a
 * remark about the implementation.
 *
 * @param  list<string>|null  $tenantIds
 * @return array{result: mixed, thrown: Throwable|null, queried: list<string>, resolved: list<string>}
 */
function planRun(MigrationSet $set, ?int $target = null, ?array $tenantIds = null): array
{
    return runFixtureConnectionsUsedBy(static fn () => app(PlanMigrationRunHandler::class)->handle(
        new PlanMigrationRun(set: $set, tenantIds: $tenantIds, targetVersion: $target)
    ));
}

/**
 * The master is the only connection the work used, and no tenant connection
 * was even built.
 *
 * @param  array{result: mixed, thrown: Throwable|null, queried: list<string>, resolved: list<string>}  $touched
 */
function expectNoTenantConnection(array $touched): void
{
    expect($touched['queried'])->toBe(['master'])
        ->and($touched['resolved'])->not->toContain('tenant');
}

/*
|--------------------------------------------------------------------------
| AC-39 - one run row, one job row per tenant, all pending
|--------------------------------------------------------------------------
*/

it('writes one run row and one job row per selected tenant, every job pending', function () {
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));
    $fixture->mapInto(DB::connection('master'), MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')))->highestVersion());

    $touched = planRun($set);

    expect($touched['thrown'])->toBeNull();
    expectNoTenantConnection($touched);

    $runs = runFixtureRunRows();
    $jobs = runFixtureJobRows();

    // Exactly one run row, and its counters say the same thing the job rows do.
    expect($runs)->toHaveCount(1)
        ->and((int) $runs[0]['target_version'])->toBe($set->highestVersion())
        ->and((string) $runs[0]['mode'])->toBe('bulk')
        ->and((string) $runs[0]['status'])->toBe('planned')
        ->and((int) $runs[0]['total'])->toBe(3)
        ->and((int) $runs[0]['pending'])->toBe(3)
        ->and((int) $runs[0]['running'])->toBe(0)
        ->and((int) $runs[0]['done'])->toBe(0)
        ->and((int) $runs[0]['failed'])->toBe(0);

    // One job per tenant, every one pending, every one belonging to the run.
    expect($jobs)->toHaveCount(3)
        ->and(array_unique(array_map(static fn (array $job): string => (string) $job['run_id'], $jobs)))
        ->toBe([(string) $runs[0]['id']])
        ->and(array_unique(array_map(static fn (array $job): string => (string) $job['status'], $jobs)))
        ->toBe(['pending'])
        ->and(array_unique(array_map(static fn (array $job): int => (int) $job['attempts'], $jobs)))
        ->toBe([0]);

    // from_version is per tenant and to_version is the run target: a plan that
    // recorded one from_version for everybody would be a plan that had not read
    // the mapping.
    $byTenant = [];

    foreach ($jobs as $job) {
        $byTenant[(string) $job['tenant_id']] = [(int) $job['from_version'], (int) $job['to_version']];
    }

    expect($byTenant[ThreeTenantFixture::EMPTY_TENANT])->toBe([0, $set->highestVersion()])
        ->and($byTenant[ThreeTenantFixture::FAILING_TENANT])->toBe([6, $set->highestVersion()]);
});

/*
|--------------------------------------------------------------------------
| AC-40 - who is selected, and in what order
|--------------------------------------------------------------------------
*/

it('selects active and read_only tenants below the target and orders them by size', function () {
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $server = masterMappingServer('plan-server');

    // The dataset AC-40 names, and the two controls that stop a selector which
    // ignores a field from passing: a tenant already **at** the target, and an
    // archived tenant at the same old version as an active one.
    $behindLarge = runFixtureTenant('behind-large', $server, 'active', 6, 9_000);
    $behindSmall = runFixtureTenant('behind-small', $server, 'read_only', 3, 1_000);
    $behindMid = runFixtureTenant('behind-mid', $server, 'active', 0, 5_000);
    $atTarget = runFixtureTenant('at-target', $server, 'active', 7, 2_000);
    $archived = runFixtureTenant('archived-old', $server, 'archived', 0, 10);
    $provisioning = runFixtureTenant('provisioning-old', $server, 'provisioning', 0, 20);

    $touched = planRun($set);

    expect($touched['thrown'])->toBeNull();
    expectNoTenantConnection($touched);

    // Exact list and exact order: ascending size_bytes, and the three that are
    // not here are each excluded by a different rule.
    expect($touched['result']->tenantIds())->toBe([$behindSmall, $behindMid, $behindLarge])
        ->and($touched['result']->tenantIds())->not->toContain($atTarget)
        ->and($touched['result']->tenantIds())->not->toContain($archived)
        ->and($touched['result']->tenantIds())->not->toContain($provisioning);

    // The table agrees with the result, in the same order: the order is part of
    // the plan, not of the reading.
    expect(array_map(static fn (array $job): string => (string) $job['tenant_id'], runFixtureJobRows()))
        ->toBe([$behindSmall, $behindMid, $behindLarge]);
});

/*
|--------------------------------------------------------------------------
| AC-51 - the status rules, both directions
|--------------------------------------------------------------------------
*/

it('accepts provisioning and failed when the run names the tenant, and refuses every other status', function () {
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $server = masterMappingServer('status-server');

    $accepted = [
        'active' => runFixtureTenant('status-active', $server, 'active', 0, 10),
        'read_only' => runFixtureTenant('status-readonly', $server, 'read_only', 0, 20),
        'provisioning' => runFixtureTenant('status-provisioning', $server, 'provisioning', 0, 30),
        'failed' => runFixtureTenant('status-failed', $server, 'failed', 0, 40),
    ];

    $refused = [
        'migrating' => runFixtureTenant('status-migrating', $server, 'migrating', 0, 50),
        'suspended' => runFixtureTenant('status-suspended', $server, 'suspended', 0, 60),
        'moving' => runFixtureTenant('status-moving', $server, 'moving', 0, 70),
        'restoring' => runFixtureTenant('status-restoring', $server, 'restoring', 0, 80),
        'archived' => runFixtureTenant('status-archived', $server, 'archived', 0, 90),
        'invented' => runFixtureTenant('status-invented', $server, 'invented', 0, 100),
    ];

    foreach ($accepted as $status => $tenantId) {
        $touched = planRun($set, null, [$tenantId]);

        // The status is in the expectation rather than in a message, so a
        // failure names which of the four accepted statuses was refused.
        expect([$status, $touched['thrown']])->toBe([$status, null])
            ->and($touched['result']->tenantIds())->toBe([$tenantId]);
    }

    DB::connection('master')->table('tenant_migration_jobs')->delete();
    DB::connection('master')->table('tenant_migration_runs')->delete();

    foreach ($refused as $status => $tenantId) {
        $touched = planRun($set, null, [$tenantId]);

        expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
            // The status is in the message: a selector that merely filtered the
            // row away could never say which status it was.
            ->and($touched['thrown']->getMessage())->toContain('['.$status.']')
            ->and($touched['thrown']->getMessage())->toContain($tenantId);

        expectNoTenantConnection($touched);
    }

    // Each refused tenant database is unchanged, and the strongest available
    // form of that under the hold is that nothing was planned for any of them.
    expect(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([]);
});

it('passes over a status a bulk run does not take, without refusing the run', function () {
    // The other half of the same rule, and the reason the two halves are not
    // one branch: AC-40 requires a bulk run to *ignore* an archived tenant,
    // while AC-51 requires a run that *names* one to say why. A single
    // implementation of either sentence breaks the other criterion.
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $server = masterMappingServer('bulk-server');
    $active = runFixtureTenant('bulk-active', $server, 'active', 0, 100);
    runFixtureTenant('bulk-suspended', $server, 'suspended', 0, 10);

    $touched = planRun($set);

    expect($touched['thrown'])->toBeNull()
        ->and($touched['result']->tenantIds())->toBe([$active]);
});

/*
|--------------------------------------------------------------------------
| AC-59 - the destructive refusal, D-10 shape
|--------------------------------------------------------------------------
*/

it('refuses a run that would apply a destructive migration, writing nothing at all', function () {
    // FX-5 drops a column. It is never applied here: the observable is that
    // the plan does not exist, which is what makes the refusal a plan-time one.
    //
    // **The tenant is at version 7 and that is the whole input, MEASURED on
    // 2026-10-15.** At version 6 this case passes with the destructive branch
    // deleted: FX-5 is also a *contract* step, so the contract guard refuses
    // the same run, with the same exception class, and both "zero job rows"
    // and "it threw MigrationRunRefused" stay green while the mechanism is
    // gone. That is the mask of plan section 7, third instance. At version 7
    // the contract guard has nothing to say - no tenant is below FX-1's
    // version - so the destructive branch is the only thing between this run
    // and a written plan.
    $set = runFixtureSet(1, 5);
    runFixturePublish($set);

    $server = masterMappingServer('destructive-server');
    runFixtureTenant('destructive-tenant', $server, 'active', 7, 100);

    $touched = planRun($set);

    // The shape is the criterion (D-33) and it is asserted first, because it
    // is the assertion that goes red when the branch is removed: zero job
    // rows, zero run rows and zero tenant connections.
    expect(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([]);

    expectNoTenantConnection($touched);

    expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
        ->and($touched['thrown']->getMessage())->toContain('fx5_contract_drops_a_column')
        // The configuration key, named in the message. Only this branch knows
        // that string - no engine error, no framework error and no other guard
        // in this stack could produce it - so the assertion pins the mechanism
        // and not a mask.
        ->and($touched['thrown']->getMessage())->toContain('schema.backups.available');
});

it('plans the same destructive run once the backup key says backups exist', function () {
    // The control that shows the refusal is keyed on the configuration key
    // rather than hard-coded. Nothing is applied here either: the plan is rows
    // in the master.
    //
    // The tenant sits at version 7 - FX-1's version, the one before FX-5 - so
    // that the only objection left to this run is the destructive one. At
    // version 6 the contract guard refuses first and the control would be
    // measuring that instead, which is the mask shape of plan section 7.
    $set = runFixtureSet(1, 5);
    runFixturePublish($set);

    config()->set('schema.backups.available', true);

    $server = masterMappingServer('backed-up-server');
    $tenant = runFixtureTenant('backed-up-tenant', $server, 'active', 7, 100);

    $touched = planRun($set);

    expect($touched['thrown'])->toBeNull()
        ->and($touched['result']->tenantIds())->toBe([$tenant])
        ->and(runFixtureJobRows())->toHaveCount(1);
});

it('does not refuse a contract step that destroys nothing', function () {
    // D-10 negative control against the obvious wrong implementation: FX-4 is
    // a contract step, like FX-5, and adds a foreign key rather than dropping a
    // column. An implementation keyed on `phase = contract` passes the refusal
    // test above and fails here.
    $set = runFixtureSet(4);
    runFixturePublish($set);

    $server = masterMappingServer('contract-ok-server');
    $tenant = runFixtureTenant('contract-ok-tenant', $server, 'active', 6, 100);

    $touched = planRun($set);

    expect($touched['thrown'])->toBeNull()
        ->and($touched['result']->tenantIds())->toBe([$tenant]);
});

/*
|--------------------------------------------------------------------------
| AC-58 - a contract step waits for the fleet
|--------------------------------------------------------------------------
*/

it('refuses a contract step while a tenant is below the version before it, naming the blockers', function () {
    $set = runFixtureSet(4);
    runFixturePublish($set);

    $server = masterMappingServer('blocked-server');
    $behind = runFixtureTenant('blocking-tenant', $server, 'active', 0, 10);
    runFixtureTenant('ready-tenant', $server, 'active', 6, 20);
    // A provisioning tenant holds nothing yet, so it does not block (AC-58).
    runFixtureTenant('provisioning-tenant', $server, 'provisioning', 0, 30);

    $touched = planRun($set);

    expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
        ->and($touched['thrown']->getMessage())->toContain('fx4_contract_adds_the_key')
        // The blocking tenant by name: only the guard knows which tenant held
        // the run up, and a reader of the message has to be told where to look.
        ->and($touched['thrown']->getMessage())->toContain($behind)
        // And the next action, not only the obstacle (ruling 14.1, landed with
        // step 13a): the V - 1 reading's known false refusal has an
        // operational remedy, and an operator meets it here or not at all.
        // `--all` is asserted with it: `--to=6` alone is refused by this
        // command's own argument guard, so the printed remedy would not run
        // (T-008 BUG-12). QaPrintedInstructionRoundTripTest calls it back.
        ->and($touched['thrown']->getMessage())->toContain('tenant:migrate --all --to=6');

    expect(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([]);

    expectNoTenantConnection($touched);
});

it('plans the contract step once every tenant a bulk run touches has the version before it', function () {
    $set = runFixtureSet(4);
    runFixturePublish($set);

    $server = masterMappingServer('ready-server');
    $one = runFixtureTenant('ready-one', $server, 'active', 6, 10);
    $two = runFixtureTenant('ready-two', $server, 'read_only', 6, 20);
    runFixtureTenant('still-provisioning', $server, 'provisioning', 0, 30);

    $touched = planRun($set);

    expect($touched['thrown'])->toBeNull()
        ->and($touched['result']->tenantIds())->toBe([$one, $two]);
});

/*
|--------------------------------------------------------------------------
| AC-32's refusal half - the runner will not start against an unpublished or
| changed catalogue
|--------------------------------------------------------------------------
*/

it('refuses to start when the set holds a migration the catalogue never published', function () {
    $set = runFixtureSet(1);
    // Deliberately not published.

    $server = masterMappingServer('unpublished-server');
    runFixtureTenant('unpublished-tenant', $server, 'active', 0, 10);

    $touched = planRun($set);

    expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
        ->and($touched['thrown']->getMessage())->toContain('create_schema_meta_table');

    expect(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([]);

    expectNoTenantConnection($touched);
});

it('refuses to start when a published migration content has moved', function () {
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $entry = $set->catalogue()[2];

    DB::connection('master')->table('schema_versions')
        ->where('version', $entry->version)
        ->update(['checksum' => str_repeat('0', 64)]);

    $server = masterMappingServer('moved-server');
    runFixtureTenant('moved-tenant', $server, 'active', 0, 10);

    $touched = planRun($set);

    expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
        // The migration by name, which the checksum comparison knows and a
        // generic "catalogue mismatch" would not.
        ->and($touched['thrown']->getMessage())->toContain($entry->migration);

    expect(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([]);
});

it('starts normally against the unchanged tree, which is the control', function () {
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $server = masterMappingServer('unchanged-server');
    $tenant = runFixtureTenant('unchanged-tenant', $server, 'active', 0, 10);

    $touched = planRun($set);

    expect($touched['thrown'])->toBeNull()
        ->and($touched['result']->tenantIds())->toBe([$tenant]);
});

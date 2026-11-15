<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Schema\Features\ReportSchemaStatus\ReportSchemaStatus;
use Flexipos\Kernel\Schema\Features\ReportSchemaStatus\ReportSchemaStatusHandler;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotActive;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Kernel\Tenancy\TenantStatusPolicy;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-008 step 13e: the migratable-status authority, proven by moving it
|--------------------------------------------------------------------------
|
| [FIX] BUG-11. Step 13b derived the planner's accepted list from the enum and
| proved "the planner keeps no copy" by searching the planner's source for the
| statuses in single quotes. `qa` wrote the same four statuses into the planner
| in **double** quotes and all eleven relevant tests stayed green. That is
| Mutant A, and it is recorded at the foot of this file as the mutation this
| file exists to close.
|
| The replacement is not a better scan (plan section 18.1). It is the proof
| step 5 used for the migration tree: **move the value and watch every
| dependent follow**. A copy of the list inside a dependent cannot follow a
| moved value whatever its spelling, quoting, concatenation or constant
| indirection, and the proof never reads the dependent's source at all.
|
| **Injectable here does not mean configurable in production** (plan section
| 18.2). Three guard-rails below are what make that true rather than claimed:
| the serving half takes no constructor argument, no configuration channel
| reaches the policy, and the shipped container resolves the shipped default.
| If a later change adds a configuration key for the migratable statuses, the
| second of those goes red and plan section 11.3 governs again.
|
| No engine: the master is the suite's in-memory SQLite and the door's half
| runs against T-003's two SQLite fixture files.
*/

require_once __DIR__.'/../Support/tenancy-fixture.php';
require_once __DIR__.'/../Support/migration-run-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();
    tenancyBootFixture();

    config()->set('schema.backups.available', false);
});

afterEach(function () {
    tenancyForgetFixture();
});

afterAll(function () {
    tenancyDeleteFixtureRoot();
});

/**
 * The status the one scratch status every moved-key case widens to.
 *
 * Deliberately a value no production code has ever heard of, so a dependent
 * that agreed by accident cannot agree with this one.
 */
function policyScratchStatus(): string
{
    return 'held-for-proof';
}

/**
 * Bind a policy over a different migratable list, as the only policy the
 * process has.
 *
 * `instance()` rather than a mutated singleton closure: the scoped Tenancy is
 * dropped first, so the door and the planner are both built *after* this and
 * both receive this object.
 *
 * @param  list<string>  $statuses
 */
function policyMove(array $statuses): TenantStatusPolicy
{
    app()->forgetScopedInstances();

    $policy = TenantStatusPolicy::withMigratableStatuses($statuses);

    app()->instance(TenantStatusPolicy::class, $policy);

    return $policy;
}

/**
 * Give the runner's mapping source one tenant in the given status.
 */
function policyDoorStatus(string $status): void
{
    $targets = tenancyFixtureMigrationTargets();
    $targets['tenant-a']['status'] = $status;

    config()->set('tenancy.migration_targets', $targets);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
}

/**
 * A planned, published set with one mapped tenant in the given status.
 */
function policyPlannedTenant(string $stem, string $status): string
{
    $set = runFixtureSet();
    runFixturePublish($set);

    return runFixtureTenant($stem, masterMappingServer('policy-server'), $status, 0, 10);
}

/*
|--------------------------------------------------------------------------
| The moved key: widen the authority and both dependents widen with it
|--------------------------------------------------------------------------
*/

it('plans and admits a status nobody shipped, once the authority names it', function () {
    $tenant = policyPlannedTenant('policy-scratch', policyScratchStatus());

    policyDoorStatus(policyScratchStatus());

    // Before the move: the shipped authority refuses it on both sides. This
    // control is why the green below is about the move and not about the
    // status being harmless.
    expect(fn () => app(PlanMigrationRunHandler::class)->handle(
        new PlanMigrationRun(set: runFixtureSet(), tenantIds: [$tenant])
    ))->toThrow(MigrationRunRefused::class, policyScratchStatus())
        ->and(fn () => tenancy()->enter('tenant-a', TenantConnectionPurpose::Migrating))
        ->toThrow(TenantDatabaseNotActive::class);

    policyMove([...TenantConnectionPurpose::MIGRATING_STATUSES, policyScratchStatus()]);

    // The planner plans it.
    $result = app(PlanMigrationRunHandler::class)
        ->handle(new PlanMigrationRun(set: runFixtureSet(), tenantIds: [$tenant]));

    expect($result->tenantIds())->toBe([$tenant]);

    // And the door admits it. A literal copy of the list in either dependent
    // leaves that dependent refusing, whatever the copy is spelled like.
    tenancy()->enter('tenant-a', TenantConnectionPurpose::Migrating);

    expect(tenancy()->current()->hasTenant())->toBeTrue();

    tenancy()->leave();
});

it('reports the moved list in the refusal message, so the planner is quoting the authority', function () {
    // The refusal names the accepted set. A planner holding its own copy
    // would print the shipped four here, which is Mutant A's visible
    // signature as well as its behavioural one.
    $tenant = policyPlannedTenant('policy-refused', 'archived');

    policyMove([...TenantConnectionPurpose::MIGRATING_STATUSES, policyScratchStatus()]);

    expect(fn () => app(PlanMigrationRunHandler::class)->handle(
        new PlanMigrationRun(set: runFixtureSet(), tenantIds: [$tenant])
    ))->toThrow(MigrationRunRefused::class, policyScratchStatus());
});

it('narrows the authority and both dependents refuse the status it dropped', function () {
    $tenant = policyPlannedTenant('policy-readonly', 'read_only');

    policyDoorStatus('read_only');

    // The control, on the shipped authority: `read_only` is planned and
    // entered. Without it, the refusal below would also be what a dependent
    // that refuses `read_only` always produces.
    $result = app(PlanMigrationRunHandler::class)
        ->handle(new PlanMigrationRun(set: runFixtureSet(), tenantIds: [$tenant]));

    expect($result->tenantIds())->toBe([$tenant]);

    tenancy()->enter('tenant-a', TenantConnectionPurpose::Migrating);
    tenancy()->leave();

    policyMove(array_values(array_filter(
        TenantConnectionPurpose::MIGRATING_STATUSES,
        static fn (string $status): bool => $status !== 'read_only',
    )));

    expect(fn () => app(PlanMigrationRunHandler::class)->handle(
        new PlanMigrationRun(set: runFixtureSet(), tenantIds: [$tenant])
    ))->toThrow(MigrationRunRefused::class, 'read_only')
        ->and(fn () => tenancy()->enter('tenant-a', TenantConnectionPurpose::Migrating))
        ->toThrow(TenantDatabaseNotActive::class);
});

it('moves the bulk list and the behind report with it', function () {
    // The third reader. `ReportSchemaStatusHandler` counts the tenants a bulk
    // run would reach, and it asks the same object - so a report that still
    // counted the shipped two would be a copy as surely as the planner's was.
    $server = masterMappingServer('policy-server');

    runFixtureTenant('policy-counted', $server, policyScratchStatus(), 1, 10);

    expect(app(ReportSchemaStatusHandler::class)->handle(new ReportSchemaStatus(6))->behind)->toBe([]);

    policyMove([...TenantConnectionPurpose::MIGRATING_STATUSES, policyScratchStatus()]);

    $behind = app(ReportSchemaStatusHandler::class)->handle(new ReportSchemaStatus(6))->behind;

    expect(array_column($behind, 'tenant_id'))->toBe([masterMappingId('policy-counted')]);
});

/*
|--------------------------------------------------------------------------
| Guard-rail 1: the serving half is structurally un-widenable
|--------------------------------------------------------------------------
|
| This is the tooth that pays for injectability and it is non-negotiable. A
| widened *migrating* list lets an operator-invoked command touch a tenant in
| an odd state; a widened *serving* list serves a shop somebody stopped.
|
*/

it('leaves serving at exactly one status however the migratable list is moved', function () {
    $policy = policyMove(['suspended', 'archived', 'moving', policyScratchStatus()]);

    expect($policy->accepted(TenantConnectionPurpose::Serving))->toBe(['active'])
        ->and($policy->permits(TenantConnectionPurpose::Serving, 'suspended'))->toBeFalse()
        ->and($policy->permits(TenantConnectionPurpose::Serving, policyScratchStatus()))->toBeFalse()
        // And the moved list did reach the object, so the line above is not
        // green because the move failed.
        ->and($policy->permits(TenantConnectionPurpose::Migrating, 'suspended'))->toBeTrue();

    // The behavioural half: the door still refuses a suspended tenant to a
    // request, with the same policy bound that admits it to the runner.
    expect(fn () => tenancy()->enter('tenant-suspended'))
        ->toThrow(TenantDatabaseNotActive::class);
});

it('reaches the serving list from no constructor argument at all', function () {
    // The structural half of guard-rail 1, by type rather than by name: the
    // only thing the constructor holds is the migratable list, so there is no
    // argument a serving answer could be read from.
    $properties = (new ReflectionClass(TenantStatusPolicy::class))->getProperties();

    expect(array_map(static fn (ReflectionProperty $p): string => $p->getName(), $properties))
        ->toBe(['migratableStatuses']);
});

/*
|--------------------------------------------------------------------------
| Guard-rail 2: no configuration channel exists
|--------------------------------------------------------------------------
|
| Plan section 11.3 forbids a guard an operator can widen without a code
| change. The policy satisfies it by taking a list rather than a configuration
| repository, and this is where that stops being a promise: every parameter
| the class accepts is checked **by type**, so the day somebody adds a
| ConfigRepository, a Container or an Application to it, this goes red and the
| reconciliation in plan section 18.2 is void.
|
*/

it('accepts no object through which configuration could reach it', function () {
    $class = new ReflectionClass(TenantStatusPolicy::class);

    $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
    $methods[] = $class->getConstructor();

    $types = [];

    foreach (array_filter($methods) as $method) {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            $types[] = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
        }
    }

    // The scan asserts its own subject is non-empty: a class with no
    // parameters at all would pass this by having nothing to look at.
    expect($types)->not->toBe([]);

    foreach ($types as $type) {
        // Scalars and the purpose enum only. Anything that is a class and is
        // not the enum is a potential channel - a repository, a container, a
        // factory - and this is the assertion that refuses it.
        expect($type === TenantConnectionPurpose::class || ! class_exists($type))->toBeTrue(
            'TenantStatusPolicy accepts ['.$type.'], which can carry configuration into the policy.'
        );

        expect(interface_exists($type))->toBeFalse(
            'TenantStatusPolicy accepts ['.$type.'], which can carry configuration into the policy.'
        );
    }
});

/*
|--------------------------------------------------------------------------
| Guard-rail 3: the shipped container resolves the shipped default
|--------------------------------------------------------------------------
|
| Step 7a's precedent, where the shipped mapping reader was asserted to be the
| caching decorator. A widening binding in any provider is red here.
|
*/

it('resolves the shipped list from the shipped container, as one singleton', function () {
    $resolved = app(TenantStatusPolicy::class);

    expect($resolved->accepted(TenantConnectionPurpose::Migrating))
        ->toBe(TenantConnectionPurpose::MIGRATING_STATUSES)
        ->and($resolved->accepted(TenantConnectionPurpose::Serving))
        ->toBe(TenantConnectionPurpose::SERVING_STATUSES)
        ->and($resolved->bulk())->toBe(['active', 'read_only'])
        ->and($resolved->namedOnly())->toBe(['provisioning', 'failed'])
        // One object for the process: two dependents resolved separately must
        // not be able to hold two different answers.
        ->and(app(TenantStatusPolicy::class))->toBe($resolved);
});

/*
|--------------------------------------------------------------------------
| The recorded mutations
|--------------------------------------------------------------------------
|
| **Mutant A, which is why this file exists.** In
| PlanMigrationRunHandler::selectNamed(), replace
|
|     $accepted = $this->statusPolicy->accepted(TenantConnectionPurpose::Migrating);
|
| with a second literal copy of the four statuses written in double quotes.
| Against step 13b's code that mutation was green in all eleven relevant
| tests, because the only structural tooth was a scan for the statuses in
| single quotes. Against this file it is red in the first three cases above,
| and the two accepted lists are in the failure message. The quoting style is
| irrelevant to the result, which is the whole point: a copy cannot follow a
| moved value however it is written.
|
| **A mutation this file does NOT catch, stated rather than left to be
| found.** Replacing `TenantStatusPolicy::shipped()` in TenancyServiceProvider
| with `withMigratableStatuses(TenantConnectionPurpose::MIGRATING_STATUSES)`
| leaves every case here green, because it resolves the same list by a
| different route. That is correct and deliberate: guard-rail 3 asserts which
| *list* the shipped container resolves, not which factory built it. A
| mutation that widens the list in that binding - the one plan section 18.2
| cares about - is red in the last case.
*/

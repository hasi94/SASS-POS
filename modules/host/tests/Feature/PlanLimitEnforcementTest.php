<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatus;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatusHandler;
use Flexipos\Access\Features\CreateEmployee\CreateEmployee;
use Flexipos\Access\Features\CreateEmployee\CreateEmployeeHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Contracts\Platform\PlanLimitReached;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Features\ChangeBranchStatus\ChangeBranchStatus;
use Flexipos\Organization\Features\ChangeBranchStatus\ChangeBranchStatusHandler;
use Flexipos\Organization\Features\ChangeLocationStatus\ChangeLocationStatus;
use Flexipos\Organization\Features\ChangeLocationStatus\ChangeLocationStatusHandler;
use Flexipos\Organization\Features\CreateBranch\CreateBranch;
use Flexipos\Organization\Features\CreateBranch\CreateBranchHandler;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminal;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalHandler;
use Flexipos\Organization\Features\CreateWarehouse\CreateWarehouse;
use Flexipos\Organization\Features\CreateWarehouse\CreateWarehouseHandler;
use Flexipos\Organization\Tests\Support\FixedTerminalSessions;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Platform\Features\ChangeSubscriptionPlan\ChangeSubscriptionPlan;
use Flexipos\Platform\Features\ChangeSubscriptionPlan\ChangeSubscriptionPlanHandler;
use Flexipos\Platform\Tests\Support\PlatformSeed;

/*
|--------------------------------------------------------------------------
| T-016 steps 10-11 - the organization and access callers (AC-1 to AC-4, AC-6)
|--------------------------------------------------------------------------
|
| Two sources, named at each test: the config stand-in
| (`platform.stand_in.entitlements.<tenant>`), the shipped way every T-010
| and T-014 test keeps running uncapped, and the master source, bound for the
| AC-3/AC-6 controls that need a real plan change.
*/

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
    app()->instance(TerminalSessions::class, new FixedTerminalSessions);
});

afterEach(function () {
    AccessTenantFixture::forget();
    config()->set('platform.stand_in.entitlements', []);
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, int>  $limits
 */
function setStandInLimits(string $tenantId, array $limits): void
{
    config()->set("platform.stand_in.entitlements.{$tenantId}", ['limits' => $limits]);
}

it('AC-2: refuses a branch at the limit with no row and no audit row, accepts one below it', function () {
    setStandInLimits('tenant-a', ['branches' => 1, 'locations' => 10]);

    app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'ONE', 'name' => 'One', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR']));

    expect(fn () => app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'TWO', 'name' => 'Two', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR'])))
        ->toThrow(PlanLimitReached::class);

    expect(OrganizationSeed::connection()->table('branches')->count())->toBe(1)
        ->and(OrganizationSeed::connection()->table('audit_logs')->count())->toBe(1);
});

it('AC-2: creating a branch with locations full is refused, and no branch row is left', function () {
    setStandInLimits('tenant-a', ['branches' => 10, 'locations' => 0]);

    expect(fn () => app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'ONE', 'name' => 'One', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR'])))
        ->toThrow(PlanLimitReached::class);

    expect(OrganizationSeed::connection()->table('branches')->count())->toBe(0);
});

it('AC-2: locations caps CreateWarehouse too', function () {
    setStandInLimits('tenant-a', ['locations' => 0]);

    expect(fn () => app(CreateWarehouseHandler::class)->handle(new CreateWarehouse(['code' => 'WH1', 'name' => 'Warehouse'])))
        ->toThrow(PlanLimitReached::class);
});

it('AC-2: terminals caps CreateTerminal', function () {
    setStandInLimits('tenant-a', ['branches' => 10, 'locations' => 10]);
    $branch = app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR']));
    $branchId = $branch->branchId;
    $locationId = $branch->locationId;

    setStandInLimits('tenant-a', ['terminals' => 1]);

    app(CreateTerminalHandler::class)->handle(new CreateTerminal(['branch_id' => $branchId, 'location_id' => $locationId, 'code' => 'T1', 'name' => 'Till 1']));

    expect(fn () => app(CreateTerminalHandler::class)->handle(new CreateTerminal(['branch_id' => $branchId, 'location_id' => $locationId, 'code' => 'T2', 'name' => 'Till 2'])))
        ->toThrow(PlanLimitReached::class);
});

it('AC-3: no terminals limit lets 30 creates all succeed, and the master-source control refuses once a plan caps it', function () {
    $branch = app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR']));
    $branchId = $branch->branchId;
    $locationId = $branch->locationId;

    for ($i = 0; $i < 30; $i++) {
        app(CreateTerminalHandler::class)->handle(new CreateTerminal(['branch_id' => $branchId, 'location_id' => $locationId, 'code' => "T{$i}", 'name' => "Till {$i}"]));
    }

    expect(OrganizationSeed::connection()->table('terminals')->count())->toBe(30);

    // The master-source control (AC-3): a plan change to one with a terminals
    // limit of 30 means the 31st create is refused.
    PlatformSeed::migrate();
    PlatformSeed::bindMasterSource();

    $tenantId = 'tenant-a';
    $oldPlan = PlatformSeed::plan();
    $newPlan = PlatformSeed::plan();
    PlatformSeed::planLimit($newPlan, 'terminals', 30);
    $subscriptionId = PlatformSeed::subscription($tenantId, $oldPlan, 'active');

    app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan($subscriptionId, $newPlan));

    expect(fn () => app(CreateTerminalHandler::class)->handle(new CreateTerminal(['branch_id' => $branchId, 'location_id' => $locationId, 'code' => 'T30', 'name' => 'Till 30'])))
        ->toThrow(PlanLimitReached::class);
});

it('AC-4: a direct insert flips the next decision with no other write, and deactivating flips it back', function () {
    setStandInLimits('tenant-a', ['branches' => 2]);

    $branchId = OrganizationSeed::branch();
    OrganizationSeed::branch(); // a second active branch, at the limit of 2.

    expect(fn () => app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'TWO', 'name' => 'Two', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR'])))
        ->toThrow(PlanLimitReached::class);

    // Deactivating the first leaves the second active, so it is not the
    // last-branch refusal.
    app(ChangeBranchStatusHandler::class)->handle(new ChangeBranchStatus($branchId, false));

    app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'TWO', 'name' => 'Two', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR']));

    expect(OrganizationSeed::connection()->table('branches')->where('is_active', true)->count())->toBe(2);
});

it('AC-2a: reactivating a branch at capacity is refused the same way a creation would be', function () {
    setStandInLimits('tenant-a', ['branches' => 1]);

    $branchId = OrganizationSeed::branch();
    OrganizationSeed::branch(); // a second active branch, so deactivating the first is not the last one.

    app(ChangeBranchStatusHandler::class)->handle(new ChangeBranchStatus($branchId, false));
    // One active branch remains, already at the limit of 1.

    expect(fn () => app(ChangeBranchStatusHandler::class)->handle(new ChangeBranchStatus($branchId, true)))
        ->toThrow(PlanLimitReached::class);
});

it('AC-2a: reactivating a location at capacity is refused the same way a creation would be', function () {
    setStandInLimits('tenant-a', ['locations' => 1]);

    $locationId = OrganizationSeed::location(null, 'WH1');
    app(ChangeLocationStatusHandler::class)->handle(new ChangeLocationStatus($locationId, false));

    OrganizationSeed::location(null, 'WH2'); // fills the one seat.

    expect(fn () => app(ChangeLocationStatusHandler::class)->handle(new ChangeLocationStatus($locationId, true)))
        ->toThrow(PlanLimitReached::class);
});

it('AC-6: a plan change to a lower limit never touches existing rows, refuses a create, and accepts only once strictly below', function () {
    setStandInLimits('tenant-a', ['branches' => 10]);

    for ($i = 0; $i < 7; $i++) {
        OrganizationSeed::branch();
    }

    $before = OrganizationSeed::connection()->table('branches')->orderBy('id')->get()->toArray();

    setStandInLimits('tenant-a', ['branches' => 5]);

    $after = OrganizationSeed::connection()->table('branches')->orderBy('id')->get()->toArray();

    expect($after)->toEqual($before);

    $branches = OrganizationSeed::connection()->table('branches')->where('is_active', true)->orderBy('id')->pluck('id')->all();

    expect(fn () => app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'EIGHT', 'name' => 'Eight', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR'])))
        ->toThrow(PlanLimitReached::class);

    // Deactivate two (down to 5): still refused.
    app(ChangeBranchStatusHandler::class)->handle(new ChangeBranchStatus($branches[0], false));
    app(ChangeBranchStatusHandler::class)->handle(new ChangeBranchStatus($branches[1], false));

    expect(fn () => app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'EIGHT', 'name' => 'Eight', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR'])))
        ->toThrow(PlanLimitReached::class);

    // Deactivate a third (down to 4): accepted.
    app(ChangeBranchStatusHandler::class)->handle(new ChangeBranchStatus($branches[2], false));

    app(CreateBranchHandler::class)->handle(new CreateBranch(['code' => 'EIGHT', 'name' => 'Eight', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR']));

    expect(true)->toBeTrue();
});

it('AC-1: refuses the sixth employee at a five-seat limit with no row, four is accepted', function () {
    setStandInLimits('tenant-a', ['employees' => 5]);

    for ($i = 0; $i < 4; $i++) {
        AccessSeed::employee("E{$i}");
    }

    app(CreateEmployeeHandler::class)->handle(new CreateEmployee('E5', 'Five'));

    expect(fn () => app(CreateEmployeeHandler::class)->handle(new CreateEmployee('E6', 'Six')))
        ->toThrow(PlanLimitReached::class);

    expect(AccessSeed::connection()->table('employees')->count())->toBe(5);
});

it('a non-seat status creates past the limit, and moving it in is refused at capacity but accepted below it', function () {
    setStandInLimits('tenant-a', ['employees' => 1]);

    AccessSeed::employee('E1');

    $resigned = app(CreateEmployeeHandler::class)->handle(new CreateEmployee('E2', 'Resigning', status: 'resigned'))->id;

    expect(fn () => app(ChangeEmployeeStatusHandler::class)->handle(new ChangeEmployeeStatus($resigned, 'suspended')))
        ->toThrow(PlanLimitReached::class);

    app(ChangeEmployeeStatusHandler::class)->handle(new ChangeEmployeeStatus(
        AccessSeed::connection()->table('employees')->where('code', 'E1')->value('id'),
        'resigned',
    ));

    app(ChangeEmployeeStatusHandler::class)->handle(new ChangeEmployeeStatus($resigned, 'suspended'));

    expect(AccessSeed::connection()->table('employees')->where('id', $resigned)->value('status'))->toBe('suspended');
});

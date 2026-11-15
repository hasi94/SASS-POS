<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\CreateWarehouse;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Features\CreateWarehouse\CreateWarehouse;
use Flexipos\Organization\Features\CreateWarehouse\CreateWarehouseHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 9 - creating a warehouse (AC-21, AC-22, AC-15, AC-20)
|--------------------------------------------------------------------------
|
| Both controls AC-21 names are here: a null-branch warehouse and a branch
| warehouse are each accepted, so the refusals are not a handler that refuses
| everything. Every refusal asserts that the location and audit counters did
| not move.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-22 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $fields
 * @return array{location_id: string, code: string}
 */
function createWarehouse(array $fields, ?string $actorId = null): array
{
    /** @var CreateWarehouseHandler $handler */
    $handler = app(CreateWarehouseHandler::class);

    return $handler->handle(new CreateWarehouse($fields, $actorId));
}

it('creates a central warehouse and a branch warehouse, with D-11 defaults and one audit row each (AC-21, AC-20)', function () {
    $actor = AccessSeed::employee('wh_actor', ['display_name' => 'Warehouse Person']);
    $branch = OrganizationSeed::branch();

    $central = createWarehouse(['code' => 'cwh', 'name' => 'Central'], $actor);

    $row = (array) OrganizationSeed::connection()->table('locations')->where('id', $central['location_id'])->first();

    expect($central['code'])->toBe('CWH')
        ->and($row['branch_id'])->toBeNull()
        ->and($row['type'])->toBe('warehouse')
        ->and((bool) $row['allow_negative_stock'])->toBeFalse()
        ->and((bool) $row['is_sellable_from'])->toBeFalse()
        ->and((bool) $row['is_purchasable_to'])->toBeTrue()
        ->and((bool) $row['is_active'])->toBeTrue()
        ->and($row['created_by'])->toBe($actor)
        ->and($row['created_at'])->toBe('2026-10-22 10:00:00.000');

    $audit = OrganizationSeed::onlyAuditRow();

    expect($audit['action'])->toBe('location_created')
        ->and($audit['subject_type'])->toBe('location')
        ->and($audit['subject_id'])->toBe($central['location_id'])
        ->and($audit['branch_id'])->toBeNull()
        ->and($audit['actor_name'])->toBe('Warehouse Person')
        ->and($audit['before'])->toBeNull()
        ->and($audit['after']['code'])->toBe('CWH');

    OrganizationSeed::connection()->table('audit_logs')->delete();

    $own = createWarehouse(['code' => 'bwh', 'name' => 'Branch', 'branch_id' => strtolower($branch), 'is_sellable_from' => true, 'allow_negative_stock' => true], $actor);

    $branchRow = (array) OrganizationSeed::connection()->table('locations')->where('id', $own['location_id'])->first();

    expect($branchRow['branch_id'])->toBe($branch)
        ->and((bool) $branchRow['is_sellable_from'])->toBeTrue()
        ->and((bool) $branchRow['allow_negative_stock'])->toBeTrue()
        ->and(OrganizationSeed::onlyAuditRow()['branch_id'])->toBe($branch);
});

it('refuses every invalid input with validation and writes nothing (AC-21)', function (array $overrides, array $remove) {
    $manager = AccessSeed::employee('mgr_ok');

    $input = array_merge(['code' => 'wh1', 'name' => 'Warehouse'], $overrides);

    foreach ($remove as $key) {
        unset($input[$key]);
    }

    $before = OrganizationSeed::counts();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createWarehouse($input));

    expect(OrganizationSeed::counts())->toBe($before)
        ->and($manager)->not->toBe('');
})->with([
    'a missing code' => [[], ['code']],
    'a malformed code' => [['code' => 'a b'], []],
    'a code over ten characters' => [['code' => 'ABCDEFGHIJK'], []],
    'a missing name' => [[], ['name']],
    'a branch id that is not a ULID' => [['branch_id' => 'branch-7'], []],
    'a non-boolean flag' => [['allow_negative_stock' => 'yes'], []],
    'a non-boolean sellable flag' => [['is_sellable_from' => 1], []],
    'a manager naming no employee' => [['manager_employee_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], []],
    'a manager that is not an id' => [['manager_employee_id' => 'nobody'], []],
    'is_active supplied' => [['is_active' => true], []],
    'id supplied' => [['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], []],
]);

it('refuses a duplicate code, a soft-deleted one included (AC-21)', function () {
    createWarehouse(['code' => 'dup', 'name' => 'One']);

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createWarehouse(['code' => 'DUP', 'name' => 'Two']), 'a live location');

    OrganizationSeed::connection()->table('locations')->where('code', 'DUP')->update(['deleted_at' => '2026-10-22 00:00:00.000']);

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createWarehouse(['code' => 'dup', 'name' => 'Three']), 'a soft-deleted location');

    expect(OrganizationSeed::connection()->table('locations')->count())->toBe(1);
});

it('refuses every type but warehouse, branch_store included, with validation and no row (AC-22)', function (mixed $type) {
    $before = OrganizationSeed::counts();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createWarehouse(['code' => 'ty1', 'name' => 'Typed', 'type' => $type]));

    expect(OrganizationSeed::counts())->toBe($before);
})->with([
    'branch_store' => ['branch_store'],
    'transit' => ['transit'],
    'consignment' => ['consignment'],
    'damaged' => ['damaged'],
    'virtual' => ['virtual'],
    'an unknown type' => ['freezer'],
    'a non-string type' => [7],
]);

it('accepts the type warehouse when it is stated (AC-22 control)', function () {
    $result = createWarehouse(['code' => 'ty2', 'name' => 'Typed', 'type' => 'warehouse']);

    expect(OrganizationSeed::connection()->table('locations')->where('id', $result['location_id'])->value('type'))->toBe('warehouse');
});

it('refuses an unknown branch as unknown_branch and an inactive one as branch_inactive, and accepts it once reactivated (AC-21, AC-15)', function () {
    $inactive = OrganizationSeed::branch(false);
    $before = OrganizationSeed::counts();

    OrganizationSeed::refusal(OrganizationRefusal::UnknownBranch, fn () => createWarehouse(['code' => 'ub1', 'name' => 'X', 'branch_id' => (string) Str::ulid()]));
    OrganizationSeed::refusal(OrganizationRefusal::BranchInactive, fn () => createWarehouse(['code' => 'ib1', 'name' => 'X', 'branch_id' => $inactive]));

    expect(OrganizationSeed::counts())->toBe($before);

    OrganizationSeed::connection()->table('branches')->where('id', $inactive)->update(['is_active' => true]);

    createWarehouse(['code' => 'ib1', 'name' => 'X', 'branch_id' => $inactive]);

    expect(OrganizationSeed::counts()['locations'])->toBe($before['locations'] + 1);
});

it('accepts a live manager, and refuses a soft-deleted one (AC-21)', function () {
    $manager = AccessSeed::employee('live_mgr');
    $gone = AccessSeed::employee('gone_mgr', ['deleted_at' => '2026-10-20 00:00:00.000']);

    $ok = createWarehouse(['code' => 'mg1', 'name' => 'Managed', 'manager_employee_id' => strtolower($manager)]);

    expect(OrganizationSeed::connection()->table('locations')->where('id', $ok['location_id'])->value('manager_employee_id'))->toBe($manager);

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => createWarehouse(['code' => 'mg2', 'name' => 'Managed', 'manager_employee_id' => $gone]));
});

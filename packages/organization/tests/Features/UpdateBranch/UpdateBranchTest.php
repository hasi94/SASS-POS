<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\UpdateBranch;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Features\CreateBranch\CreateBranch;
use Flexipos\Organization\Features\CreateBranch\CreateBranchHandler;
use Flexipos\Organization\Features\UpdateBranch\UpdateBranch;
use Flexipos\Organization\Features\UpdateBranch\UpdateBranchHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 8 - updating a branch (AC-12, AC-20)
|--------------------------------------------------------------------------
|
| The refusal of a field the operation does not take is half of AC-12 and its
| control is the other half: the same request without those fields succeeds.
| "Stored values unchanged" is read from the row and from the audit table, and
| `perm_version` is read for an employee, because AC-12 says no employee's
| changes.
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
 * A branch created through the slice, so the row is the shipped shape.
 *
 * @return array{branch: string, actor: string, employee: string}
 */
function updateBranchWorld(): array
{
    $actor = AccessSeed::employee('updater', ['display_name' => 'Update Person']);
    $employee = AccessSeed::employee('bystander');

    /** @var CreateBranchHandler $create */
    $create = app(CreateBranchHandler::class);

    $result = $create->handle(new CreateBranch([
        'code' => 'upd', 'name' => 'Before', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ], $actor));

    OrganizationSeed::connection()->table('audit_logs')->delete();

    return ['branch' => $result->branchId, 'actor' => $actor, 'employee' => $employee];
}

/**
 * @param  array<string, mixed>  $fields
 */
function updateBranch(string $branchId, array $fields, ?string $actorId): void
{
    /** @var UpdateBranchHandler $handler */
    $handler = app(UpdateBranchHandler::class);

    $handler->handle(new UpdateBranch($branchId, $fields, $actorId));
}

it('changes every editable field, moves updated_at and updated_by, writes one audit row and bumps nobody (AC-12, AC-20)', function () {
    $ids = updateBranchWorld();
    $version = AccessSeed::permVersion($ids['employee']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-22 11:30:00.000'));

    updateBranch($ids['branch'], [
        'name' => 'After',
        'address' => ['line1' => '1 Main Street', 'city' => 'Colombo'],
        'phone' => '0112223344',
        'email' => 'shop@example.com',
        'timezone' => 'Asia/Kolkata',
        'currency' => 'inr',
        'tax_registration_no' => 'TAX-1',
        'business_type' => 'both',
        'opening_date' => '2026-11-01',
        'sort_order' => 9,
    ], $ids['actor']);

    $row = (array) OrganizationSeed::connection()->table('branches')->where('id', $ids['branch'])->first();

    expect($row['name'])->toBe('After')
        ->and(json_decode((string) $row['address'], true))->toBe(['line1' => '1 Main Street', 'city' => 'Colombo'])
        ->and($row['phone'])->toBe('0112223344')
        ->and($row['email'])->toBe('shop@example.com')
        ->and($row['timezone'])->toBe('Asia/Kolkata')
        ->and($row['currency'])->toBe('INR')
        ->and($row['tax_registration_no'])->toBe('TAX-1')
        ->and($row['business_type'])->toBe('both')
        ->and($row['opening_date'])->toBe('2026-11-01')
        ->and((int) $row['sort_order'])->toBe(9)
        ->and($row['updated_at'])->toBe('2026-10-22 11:30:00.000')
        ->and($row['updated_at'])->not->toBe($row['created_at'])
        ->and($row['updated_by'])->toBe($ids['actor'])
        // The code and the activation state are not among the editable fields.
        ->and($row['code'])->toBe('UPD')
        ->and((bool) $row['is_active'])->toBeTrue()
        ->and(AccessSeed::permVersion($ids['employee']))->toBe($version);

    $audit = OrganizationSeed::onlyAuditRow();

    expect($audit['action'])->toBe('branch_updated')
        ->and($audit['subject_id'])->toBe($ids['branch'])
        ->and($audit['branch_id'])->toBe($ids['branch'])
        ->and($audit['actor_id'])->toBe($ids['actor'])
        ->and($audit['before']['name'])->toBe('Before')
        ->and($audit['after']['name'])->toBe('After')
        ->and($audit['changed_fields'])->toBe([
            'address', 'business_type', 'currency', 'email', 'name', 'opening_date', 'phone', 'sort_order',
            'tax_registration_no', 'timezone',
        ]);
});

it('changes only the fields it was given', function () {
    $ids = updateBranchWorld();

    updateBranch($ids['branch'], ['phone' => '0771234567'], $ids['actor']);

    $row = (array) OrganizationSeed::connection()->table('branches')->where('id', $ids['branch'])->first();

    expect($row['phone'])->toBe('0771234567')
        ->and($row['name'])->toBe('Before')
        ->and($row['timezone'])->toBe('Asia/Colombo')
        ->and(OrganizationSeed::onlyAuditRow()['changed_fields'])->toBe(['phone']);

    // A null clears an optional field and nothing else.
    updateBranch($ids['branch'], ['phone' => null], $ids['actor']);

    expect(OrganizationSeed::connection()->table('branches')->where('id', $ids['branch'])->value('phone'))->toBeNull();
});

it('refuses the fields the operation does not take, changes nothing and writes no audit row, and accepts the same request without them (AC-12)', function (string $field, mixed $value) {
    $ids = updateBranchWorld();
    $before = (array) OrganizationSeed::connection()->table('branches')->where('id', $ids['branch'])->first();

    OrganizationSeed::refusal(
        OrganizationRefusal::Validation,
        fn () => updateBranch($ids['branch'], ['name' => 'Sneaky', $field => $value], $ids['actor'])
    );

    expect((array) OrganizationSeed::connection()->table('branches')->where('id', $ids['branch'])->first())->toBe($before)
        ->and(OrganizationSeed::audit())->toBe([]);

    // The control: the same request without the refused field succeeds.
    updateBranch($ids['branch'], ['name' => 'Sneaky'], $ids['actor']);

    expect(OrganizationSeed::connection()->table('branches')->where('id', $ids['branch'])->value('name'))->toBe('Sneaky');
})->with([
    'code' => ['code', 'NEW'],
    'id' => ['id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'is_active' => ['is_active', false],
    'default_location_id' => ['default_location_id', null],
    'deleted_at' => ['deleted_at', '2026-10-22 00:00:00.000'],
    'created_by' => ['created_by', null],
]);

it('refuses invalid values and an empty update, and a business_type outside the set (AC-11, AC-12)', function (array $fields) {
    $ids = updateBranchWorld();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => updateBranch($ids['branch'], $fields, $ids['actor']));

    expect(OrganizationSeed::audit())->toBe([]);
})->with([
    'an empty update' => [[]],
    'a blank name' => [['name' => ' ']],
    'a null name' => [['name' => null]],
    'a null timezone' => [['timezone' => null]],
    'a bad timezone' => [['timezone' => 'Nowhere/Land']],
    'a bad currency' => [['currency' => 'EURO']],
    'a bad email' => [['email' => 'nope']],
    'a bad business type' => [['business_type' => 'wholesale']],
    'a string sort order' => [['sort_order' => '3']],
    'a list as the address' => [['address' => ['a', 'b']]],
]);

it('answers not_found for an unknown branch, a soft-deleted one and a malformed id as validation, and resolves a lower-case id (AC-12, AC-13, AC-61)', function () {
    $ids = updateBranchWorld();

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => updateBranch('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['name' => 'x'], $ids['actor']));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => updateBranch('not-an-id', ['name' => 'x'], $ids['actor']));

    updateBranch(strtolower($ids['branch']), ['name' => 'Lower'], $ids['actor']);

    expect(OrganizationSeed::connection()->table('branches')->where('id', $ids['branch'])->value('name'))->toBe('Lower');

    OrganizationSeed::connection()->table('branches')->where('id', $ids['branch'])->update(['deleted_at' => '2026-10-22 00:00:00.000']);

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => updateBranch($ids['branch'], ['name' => 'x'], $ids['actor']));
});

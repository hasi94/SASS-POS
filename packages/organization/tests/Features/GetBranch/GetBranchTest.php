<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\GetBranch;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\GetBranch\GetBranch;
use Flexipos\Organization\Features\GetBranch\GetBranchHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 8 - reading branches (AC-13)
|--------------------------------------------------------------------------
|
| The list is ordered by `sort_order` then `code`, and the fixture is built so
| that either key alone gives a different order from both together: two
| branches share a sort order and differ in code, and one has a later sort
| order with an earlier code. A list ordered by code only, or by sort order
| only, fails.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Four branches inserted in an order that is none of the expected ones.
 *
 * @return array<string, string>
 */
function getBranchWorld(): array
{
    $insert = static function (string $code, int $sort, bool $active = true, ?string $deleted = null): string {
        $id = OrganizationSeed::branch($active);

        OrganizationSeed::connection()->table('branches')->where('id', $id)->update([
            'code' => $code, 'sort_order' => $sort, 'deleted_at' => $deleted,
        ]);

        return $id;
    };

    return [
        'late' => $insert('AAA', 5),
        'b' => $insert('BBB', 1),
        'inactive' => $insert('ABC', 1, false),
        'gone' => $insert('ZZZ', 0, true, '2026-10-22 00:00:00.000'),
    ];
}

it('returns the table fields and the default location id for one branch, resolving a lower-case id (AC-13, AC-61)', function () {
    $ids = getBranchWorld();
    $store = OrganizationSeed::location($ids['b'], 'STORE', 'branch_store');

    OrganizationSeed::connection()->table('branches')->where('id', $ids['b'])->update(['default_location_id' => $store]);

    /** @var GetBranchHandler $handler */
    $handler = app(GetBranchHandler::class);

    $view = $handler->handle(new GetBranch(strtolower($ids['b'])));

    expect(array_keys($view))->toBe([
        'id', 'code', 'name', 'address', 'phone', 'email', 'timezone', 'currency', 'tax_registration_no',
        'business_type', 'opening_date', 'default_location_id', 'is_active', 'sort_order',
        'created_at', 'updated_at', 'created_by', 'updated_by',
    ])
        ->and($view['id'])->toBe($ids['b'])
        ->and($view['default_location_id'])->toBe($store)
        ->and($view['is_active'])->toBeTrue()
        ->and($view['sort_order'])->toBe(1);
});

it('answers not_found for an id that is not here, a soft-deleted branch and another tenants well-formed id, and validation for a malformed one (AC-13)', function () {
    $ids = getBranchWorld();

    /** @var GetBranchHandler $handler */
    $handler = app(GetBranchHandler::class);

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new GetBranch('01ARZ3NDEKTSV4RRFFQ69G5FAV')), 'unknown');
    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new GetBranch($ids['gone'])), 'soft-deleted');
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new GetBranch('nope')), 'malformed');

    // Another tenant's branch: a well-formed id that exists only in tenant B.
    AccessTenantFixture::switchTo('tenant-b');
    $other = OrganizationSeed::branch();
    AccessTenantFixture::switchTo('tenant-a');

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new GetBranch($other)), 'another tenant');
});

<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ListBranches;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ListBranches\ListBranches;
use Flexipos\Organization\Features\ListBranches\ListBranchesHandler;
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
function listBranchesWorld(): array
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

it('lists by sort order then code, includes inactive branches with their flag, and excludes a soft-deleted one (AC-13)', function () {
    $ids = listBranchesWorld();

    /** @var ListBranchesHandler $handler */
    $handler = app(ListBranchesHandler::class);

    $list = $handler->handle(new ListBranches);

    expect(array_column($list, 'id'))->toBe([$ids['inactive'], $ids['b'], $ids['late']])
        ->and(array_column($list, 'code'))->toBe(['ABC', 'BBB', 'AAA'])
        ->and(array_column($list, 'is_active'))->toBe([false, true, true])
        ->and(array_column($list, 'id'))->not->toContain($ids['gone']);
});

it('clamps the page to the configured maximum, and honours a smaller request (AC-13)', function () {
    listBranchesWorld();

    config()->set('organization.page_size', 2);
    config()->set('organization.max_page_size', 3);

    /** @var ListBranchesHandler $handler */
    $handler = app(ListBranchesHandler::class);

    expect($handler->handle(new ListBranches))->toHaveCount(2)
        ->and($handler->handle(new ListBranches(1)))->toHaveCount(1)
        ->and($handler->handle(new ListBranches(1000)))->toHaveCount(3)
        ->and($handler->handle(new ListBranches(0)))->toHaveCount(2);
});

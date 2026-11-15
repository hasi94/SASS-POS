<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\ListCustomers;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Features\ListCustomers\ListCustomers;
use Flexipos\Customers\Features\ListCustomers\ListCustomersHandler;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 9 - listing customers, filtered and keyset-paged
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/customers-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    customersEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function listCustomers(?string $groupId = null, ?string $status = null, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListCustomersHandler $handler */
    $handler = app(ListCustomersHandler::class);

    return $handler->handle(new ListCustomers($groupId, $status, $pageSize, $beforeId));
}

it('excludes soft-deleted customers', function () {
    $live = CustomersSeed::customer('Live');
    $deleted = CustomersSeed::customer('Deleted', ['deleted_at' => CustomersSeed::NOW]);

    $ids = array_column(listCustomers()->rows, 'id');
    expect($ids)->toContain($live);
    expect($ids)->not->toContain($deleted);
});

it('filters by group_id and status', function () {
    $groupId = CustomersSeed::group('A');
    $inGroup = CustomersSeed::customer('InGroup', ['group_id' => $groupId]);
    $outGroup = CustomersSeed::customer('OutGroup');
    $blocked = CustomersSeed::customer('Blocked', ['status' => 'blocked', 'blocked_reason' => 'x']);

    $ids = array_column(listCustomers($groupId)->rows, 'id');
    expect($ids)->toBe([$inGroup]);

    $ids = array_column(listCustomers(null, 'blocked')->rows, 'id');
    expect($ids)->toBe([$blocked]);

    expect($outGroup)->toBeString();
});

it('pages 3 rows at size 2: two rows plus a cursor, then one row and no cursor', function () {
    CustomersSeed::customer('A');
    CustomersSeed::customer('B');
    CustomersSeed::customer('C');

    $page1 = listCustomers(null, null, 2);
    expect($page1->rows)->toHaveCount(2)->and($page1->nextBeforeId)->not->toBeNull();

    $page2 = listCustomers(null, null, 2, $page1->nextBeforeId);
    expect($page2->rows)->toHaveCount(1)->and($page2->nextBeforeId)->toBeNull();
});

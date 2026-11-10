<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Features\ListCustomerGroups;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Customers\Features\ListCustomerGroups\ListCustomerGroups;
use Flexipos\Customers\Features\ListCustomerGroups\ListCustomerGroupsHandler;
use Flexipos\Customers\Support\Page;
use Flexipos\Customers\Tests\Support\CustomersSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-028 plan step 8 - listing customer groups, keyset-paged (Ruling 11)
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

function listCustomerGroups(?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListCustomerGroupsHandler $handler */
    $handler = app(ListCustomerGroupsHandler::class);

    return $handler->handle(new ListCustomerGroups($pageSize, $beforeId));
}

it('excludes soft-deleted groups', function () {
    $live = CustomersSeed::group('LIVE');
    $deleted = CustomersSeed::group('DELETED', ['deleted_at' => CustomersSeed::NOW]);

    $page = listCustomerGroups();

    $ids = array_column($page->rows, 'id');
    expect($ids)->toContain($live);
    expect($ids)->not->toContain($deleted);
});

it('pages 3 rows at size 2: two rows plus a cursor, then one row and no cursor', function () {
    CustomersSeed::group('A');
    CustomersSeed::group('B');
    CustomersSeed::group('C');

    $page1 = listCustomerGroups(2);
    expect($page1->rows)->toHaveCount(2)->and($page1->nextBeforeId)->not->toBeNull();

    $page2 = listCustomerGroups(2, $page1->nextBeforeId);
    expect($page2->rows)->toHaveCount(1)->and($page2->nextBeforeId)->toBeNull();
});

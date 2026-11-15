<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\ListSales;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\ListSales\ListSales;
use Flexipos\Sales\Features\ListSales\ListSalesHandler;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 18 - ListSales (AC-20; D-26)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/sales-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    salesEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function lsList(string $branchId, ?string $status = null, ?string $type = null, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListSalesHandler $handler */
    $handler = app(ListSalesHandler::class);

    return $handler->handle(new ListSales($branchId, $status, $type, $pageSize, $beforeId));
}

it('lists a sale held at terminal A for the whole branch, and nothing of another branch (AC-20)', function () {
    $a = SalesWorld::till();
    $b = SalesWorld::secondTill($a);
    $elsewhere = SalesWorld::till('TE');

    $held = SalesWorld::sale($a, ['status' => 'held']);
    SalesWorld::sale($a, ['status' => 'draft']);
    SalesWorld::sale($elsewhere, ['status' => 'held']);

    // Asked on behalf of terminal B's branch: the list is the branch's, whichever till parked the sale.
    $page = lsList($b['branch'], 'held');

    expect(array_column($page->rows, 'id'))->toBe([$held])
        ->and($page->rows[0]['terminal_id'])->toBe($a['terminal']);
});

it('pages newest first and reaches the last row by the cursor', function () {
    $till = SalesWorld::till();
    $ids = [];

    foreach (range(1, 5) as $unused) {
        $ids[] = SalesWorld::sale($till, ['status' => 'held']);
    }

    $first = lsList($till['branch'], 'held', null, 2);
    $second = lsList($till['branch'], 'held', null, 2, $first->nextBeforeId);
    $third = lsList($till['branch'], 'held', null, 2, $second->nextBeforeId);

    expect(array_column($first->rows, 'id'))->toBe([$ids[4], $ids[3]])
        ->and(array_column($second->rows, 'id'))->toBe([$ids[2], $ids[1]])
        ->and(array_column($third->rows, 'id'))->toBe([$ids[0]])
        ->and($third->nextBeforeId)->toBeNull();
});

it('reaches row 51 of 51 at the default page size', function () {
    $till = SalesWorld::till();

    foreach (range(1, 51) as $unused) {
        SalesWorld::sale($till);
    }

    $first = lsList($till['branch']);
    $second = lsList($till['branch'], null, null, null, $first->nextBeforeId);

    expect($first->rows)->toHaveCount(50)->and($second->rows)->toHaveCount(1);
});

it('filters by type, and every status is accepted', function () {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till);
    $refund = SalesWorld::sale($till, ['type' => 'refund']);

    expect(array_column(lsList($till['branch'], null, 'refund')->rows, 'id'))->toBe([$refund])
        ->and(array_column(lsList($till['branch'], null, 'sale')->rows, 'id'))->toBe([$sale]);

    foreach (['draft', 'held', 'pending_payment', 'completed', 'voided', 'refunded', 'partially_refunded', 'failed'] as $status) {
        expect(lsList($till['branch'], $status)->rows)->toBeArray();
    }
});

it('refuses an unknown status or type, and a malformed branch or cursor, as validation', function () {
    $till = SalesWorld::till();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => lsList($till['branch'], 'parked'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => lsList($till['branch'], null, 'quote'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => lsList('x'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => lsList($till['branch'], null, null, null, 'x'));
});

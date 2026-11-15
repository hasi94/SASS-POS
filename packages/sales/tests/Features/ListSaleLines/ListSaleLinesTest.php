<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\ListSaleLines;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\ListSaleLines\ListSaleLines;
use Flexipos\Sales\Features\ListSaleLines\ListSaleLinesHandler;
use Flexipos\Sales\Support\Page;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 14 - ListSaleLines (D-26)
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

function lslList(string $saleId, ?int $pageSize = null, ?string $beforeId = null): Page
{
    /** @var ListSaleLinesHandler $handler */
    $handler = app(ListSaleLinesHandler::class);

    return $handler->handle(new ListSaleLines($saleId, $pageSize, $beforeId));
}

it('lists one sale\'s lines only, newest first, and reaches the last page by the cursor', function () {
    $till = SalesWorld::till();
    $catalogue = SalesSeed::catalogue();
    $sale = SalesWorld::sale($till);
    $other = SalesWorld::sale($till);

    $ids = [];

    foreach (range(1, 5) as $lineNo) {
        $ids[] = SalesSeed::line($sale, $catalogue, $lineNo);
    }

    SalesSeed::line($other, $catalogue, 1);

    $first = lslList($sale, 2);
    $second = lslList($sale, 2, $first->nextBeforeId);
    $third = lslList($sale, 2, $second->nextBeforeId);

    expect(array_column($first->rows, 'id'))->toBe([$ids[4], $ids[3]])
        ->and(array_column($second->rows, 'id'))->toBe([$ids[2], $ids[1]])
        ->and(array_column($third->rows, 'id'))->toBe([$ids[0]])
        ->and($third->nextBeforeId)->toBeNull();
});

it('reaches line 51 of 51 at the default page size', function () {
    $till = SalesWorld::till();
    $catalogue = SalesSeed::catalogue();
    $sale = SalesWorld::sale($till);

    foreach (range(1, 51) as $lineNo) {
        SalesSeed::line($sale, $catalogue, $lineNo);
    }

    $first = lslList($sale);
    $second = lslList($sale, null, $first->nextBeforeId);

    expect($first->rows)->toHaveCount(50)->and($second->rows)->toHaveCount(1)->and($second->nextBeforeId)->toBeNull();
});

it('is not_found for a sale that does not exist, so an empty page means no lines', function () {
    $till = SalesWorld::till();

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => lslList((string) Str::ulid()));

    expect(lslList(SalesWorld::sale($till))->rows)->toBe([]);
});

it('refuses a malformed sale id or cursor as validation', function () {
    $till = SalesWorld::till();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => lslList('x'));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => lslList(SalesWorld::sale($till), null, 'x'));
});

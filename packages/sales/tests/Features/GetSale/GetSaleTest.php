<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\GetSale;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\GetSale\GetSale;
use Flexipos\Sales\Features\GetSale\GetSaleHandler;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 18 - GetSale (D-26): the header and the tenders
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

it('returns the header and this sale\'s tenders only, in the order they were paid', function () {
    $till = SalesWorld::till();
    $sale = SalesWorld::sale($till, ['total' => '45.0000', 'status' => 'pending_payment']);
    $other = SalesWorld::sale($till);
    $cash = SalesSeed::paymentMethod('CASH');
    $card = SalesSeed::paymentMethod('CARD', ['type' => 'card']);

    $second = SalesSeed::tender($sale, $card, 'card', '25.0000', ['paid_at' => '2026-11-12 09:05:00.000']);
    $first = SalesSeed::tender($sale, $cash, 'cash', '20.0000', ['paid_at' => '2026-11-12 09:01:00.000']);
    SalesSeed::tender($other, $cash, 'cash', '1.0000');

    /** @var GetSaleHandler $handler */
    $handler = app(GetSaleHandler::class);
    $result = $handler->handle(new GetSale(strtolower($sale)));

    expect($result->sale)->toMatchArray(['id' => $sale, 'status' => 'pending_payment'])
        ->and(array_column($result->tenders, 'id'))->toBe([$first, $second]);
});

it('is not_found for a sale that is not there, and validation for a malformed id', function () {
    /** @var GetSaleHandler $handler */
    $handler = app(GetSaleHandler::class);

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => $handler->handle(new GetSale((string) Str::ulid())));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => $handler->handle(new GetSale('x')));
});

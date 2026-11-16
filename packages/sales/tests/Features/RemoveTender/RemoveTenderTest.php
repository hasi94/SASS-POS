<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\RemoveTender;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\RemoveTender\RemoveTender;
use Flexipos\Sales\Features\RemoveTender\RemoveTenderHandler;
use Flexipos\Sales\Features\RemoveTender\RemoveTenderResult;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 16 - RemoveTender (AC-13; D-29)
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

/**
 * A pending sale owing 45.0000 with a cash tender and a credit tender on it.
 *
 * @return array<string, mixed>
 */
function rtWorld(string $status = 'pending_payment'): array
{
    $till = SalesWorld::till();
    $customer = SalesWorld::creditCustomer();
    $sale = SalesWorld::sale($till, ['status' => $status, 'customer_id' => $customer, 'total' => '45.0000', 'subtotal' => '45.0000']);
    $cash = SalesSeed::paymentMethod('CASH', ['type' => 'cash']);
    $credit = SalesSeed::paymentMethod('CREDIT', ['type' => 'credit']);

    return [
        'till' => $till,
        'sale' => $sale,
        'cashTender' => SalesSeed::tender($sale, $cash, 'cash', '20.0000'),
        'creditTender' => SalesSeed::tender($sale, $credit, 'credit', '25.0000'),
    ];
}

/**
 * @param  array<string, mixed>  $fields
 */
function rtRemove(array $fields, ?string $actorId): RemoveTenderResult
{
    /** @var RemoveTenderHandler $handler */
    $handler = app(RemoveTenderHandler::class);

    return $handler->handle(new RemoveTender($fields, $actorId));
}

it('deletes the row outright and leaves the sale pending_payment while tenders remain (AC-13)', function () {
    $w = rtWorld();

    $result = rtRemove(['sale_id' => $w['sale'], 'tender_id' => strtolower($w['cashTender'])], $w['till']['cashier']);

    expect($result->saleStatus)->toBe('pending_payment')
        ->and(SalesSeed::connection()->table('sale_tenders')->where('id', $w['cashTender'])->exists())->toBeFalse()
        ->and(SalesSeed::connection()->table('sale_tenders')->where('id', $w['creditTender'])->exists())->toBeTrue()
        ->and(SalesSeed::row('sales', $w['sale'])['status'])->toBe('pending_payment');

    expect(SalesSeed::onlyAuditRow())->toMatchArray(['action' => 'sales_tender_removed', 'subject_type' => 'sale_tender', 'subject_id' => $w['cashTender'], 'after' => null]);
});

it('returns the sale to draft when the last tender goes, a credit tender included, with no ledger entry to unwind (AC-13, F-3)', function () {
    $w = rtWorld();

    rtRemove(['sale_id' => $w['sale'], 'tender_id' => $w['cashTender']], $w['till']['cashier']);
    $result = rtRemove(['sale_id' => $w['sale'], 'tender_id' => $w['creditTender']], $w['till']['cashier']);

    expect($result->saleStatus)->toBe('draft')
        ->and(SalesSeed::row('sales', $w['sale'])['status'])->toBe('draft')
        ->and(SalesSeed::connection()->table('sale_tenders')->count())->toBe(0)
        ->and(SalesSeed::connection()->table('customer_ledger')->count())->toBe(0);
});

it('refuses once the sale is anything but pending_payment, and leaves the tender where it is', function (string $status) {
    $w = rtWorld($status);
    $before = SalesSeed::connection()->table('sale_tenders')->count();

    SalesRefusals::expect(SalesRefusal::TenderNotRemovable, fn () => rtRemove(['sale_id' => $w['sale'], 'tender_id' => $w['cashTender']], $w['till']['cashier']));

    expect(SalesSeed::connection()->table('sale_tenders')->count())->toBe($before)
        ->and(SalesSeed::row('sales', $w['sale'])['status'])->toBe($status);
})->with(['completed', 'voided', 'refunded', 'partially_refunded', 'held']);

it('is not_found for a tender that is not on the named sale, one that is gone, and a sale that is not there', function () {
    $w = rtWorld();
    $other = SalesWorld::sale($w['till']);

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rtRemove(['sale_id' => $other, 'tender_id' => $w['cashTender']], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rtRemove(['sale_id' => $w['sale'], 'tender_id' => (string) Str::ulid()], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rtRemove(['sale_id' => (string) Str::ulid(), 'tender_id' => $w['cashTender']], $w['till']['cashier']));

    expect(SalesSeed::connection()->table('sale_tenders')->count())->toBe(2);
});

it('refuses malformed ids, other fields and a missing actor as validation', function () {
    $w = rtWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => rtRemove(['sale_id' => 'x', 'tender_id' => $w['cashTender']], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rtRemove(['sale_id' => $w['sale'], 'tender_id' => 'x'], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rtRemove(['sale_id' => $w['sale'], 'tender_id' => $w['cashTender'], 'amount' => '1'], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rtRemove(['sale_id' => $w['sale'], 'tender_id' => $w['cashTender']], null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rtRemove(['sale_id' => $w['sale'], 'tender_id' => $w['cashTender']], (string) Str::ulid()));
});

it('takes the sale FOR UPDATE as the first statement inside the transaction', function () {
    $w = rtWorld();

    $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => rtRemove(['sale_id' => $w['sale'], 'tender_id' => $w['cashTender']], $w['till']['cashier'])));

    expect($inside[0])->toContain('"sales"')->and($inside[0])->toContain(LockMarkingGrammar::FOR_UPDATE);
});

it('moves paid_total and balance_due with every removal, back to nothing paid when the last goes (W-3)', function () {
    $w = rtWorld();

    rtRemove(['sale_id' => $w['sale'], 'tender_id' => $w['cashTender']], $w['till']['cashier']);

    expect(SalesSeed::row('sales', $w['sale']))->toMatchArray(['paid_total' => '25.0000', 'balance_due' => '20.0000']);

    rtRemove(['sale_id' => $w['sale'], 'tender_id' => $w['creditTender']], $w['till']['cashier']);

    expect(SalesSeed::row('sales', $w['sale']))->toMatchArray(['status' => 'draft', 'paid_total' => '0.0000', 'balance_due' => '45.0000']);
});

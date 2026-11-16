<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\RemoveSaleLine;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\RemoveSaleLine\RemoveSaleLine;
use Flexipos\Sales\Features\RemoveSaleLine\RemoveSaleLineHandler;
use Flexipos\Sales\Features\RemoveSaleLine\RemoveSaleLineResult;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 14 - RemoveSaleLine (AC-6, AC-8)
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
 * @return array<string, mixed>
 */
function rslWorld(): array
{
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TX10', '10.0000');
    $catalogue = SalesWorld::product('P1', '100.0000', $group);

    return ['till' => $till, 'sale' => SalesWorld::sale($till), 'product' => $catalogue['product'], 'unit' => $catalogue['unit']];
}

/**
 * @param  array<string, mixed>  $w
 */
function rslLine(array $w, string $quantity = '1'): string
{
    /** @var AddSaleLineHandler $handler */
    $handler = app(AddSaleLineHandler::class);

    return $handler->handle(new AddSaleLine(['sale_id' => $w['sale'], 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => $quantity], $w['till']['cashier']))->lineId;
}

/**
 * @param  array<string, mixed>  $fields
 */
function rslRemove(array $fields, ?string $actorId): RemoveSaleLineResult
{
    /** @var RemoveSaleLineHandler $handler */
    $handler = app(RemoveSaleLineHandler::class);

    return $handler->handle(new RemoveSaleLine($fields, $actorId));
}

it('removes the line and re-sums the header from the lines that are left (AC-8)', function () {
    $w = rslWorld();
    $first = rslLine($w, '1');
    $second = rslLine($w, '2');

    $result = rslRemove(['sale_id' => $w['sale'], 'line_id' => strtolower($first)], $w['till']['cashier']);

    expect($result->saleTotal)->toBe('220.0000')
        ->and(SalesSeed::connection()->table('sale_items')->where('id', $first)->exists())->toBeFalse()
        ->and(SalesSeed::connection()->table('sale_items')->where('id', $second)->exists())->toBeTrue()
        ->and(SalesSeed::row('sales', $w['sale']))->toMatchArray(['subtotal' => '200.0000', 'tax_total' => '20.0000', 'total' => '220.0000', 'balance_due' => '220.0000']);

    rslRemove(['sale_id' => $w['sale'], 'line_id' => $second], $w['till']['cashier']);

    // Zero lines give all zeros.
    expect(SalesSeed::row('sales', $w['sale']))->toMatchArray(['subtotal' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'total' => '0.0000', 'balance_due' => '0.0000']);
});

it('writes one audit row with the line before and nothing after', function () {
    $w = rslWorld();
    $line = rslLine($w);
    SalesSeed::connection()->table('audit_logs')->delete();

    rslRemove(['sale_id' => $w['sale'], 'line_id' => $line], $w['till']['cashier']);

    $audit = SalesSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'sales_sale_line_removed', 'subject_type' => 'sale_item', 'subject_id' => $line, 'after' => null])
        ->and($audit['before']['id'])->toBe($line);
});

it('refuses a line that is not on the named sale, and one that does not exist, as not_found', function () {
    $w = rslWorld();
    $line = rslLine($w);
    $otherSale = SalesWorld::sale($w['till']);

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rslRemove(['sale_id' => $otherSale, 'line_id' => $line], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rslRemove(['sale_id' => $w['sale'], 'line_id' => (string) Str::ulid()], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => rslRemove(['sale_id' => (string) Str::ulid(), 'line_id' => $line], $w['till']['cashier']));

    expect(SalesSeed::connection()->table('sale_items')->where('id', $line)->exists())->toBeTrue();
});

it('refuses once the sale\'s lines are locked or the sale is finished', function (string $status) {
    $w = rslWorld();
    $line = rslLine($w);
    SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['status' => $status]);

    SalesRefusals::expect(SalesRefusal::SaleNotEditable, fn () => rslRemove(['sale_id' => $w['sale'], 'line_id' => $line], $w['till']['cashier']));

    expect(SalesSeed::connection()->table('sale_items')->where('id', $line)->exists())->toBeTrue();
})->with(['pending_payment', 'completed', 'voided']);

it('refuses malformed ids, other fields, and a missing actor', function () {
    $w = rslWorld();
    $line = rslLine($w);

    SalesRefusals::expect(SalesRefusal::Validation, fn () => rslRemove(['sale_id' => 'x', 'line_id' => $line], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rslRemove(['sale_id' => $w['sale'], 'line_id' => 'x'], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rslRemove(['sale_id' => $w['sale'], 'line_id' => $line, 'quantity' => '1'], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::Validation, fn () => rslRemove(['sale_id' => $w['sale'], 'line_id' => $line], null));
});

it('takes the sale FOR UPDATE as the first statement inside the transaction', function () {
    $w = rslWorld();
    $line = rslLine($w);

    $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => rslRemove(['sale_id' => $w['sale'], 'line_id' => $line], $w['till']['cashier'])));

    expect($inside[0])->toContain('"sales"')->and($inside[0])->toContain(LockMarkingGrammar::FOR_UPDATE);
});

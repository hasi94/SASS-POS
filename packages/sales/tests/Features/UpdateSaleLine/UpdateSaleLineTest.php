<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\UpdateSaleLine;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineResult;
use Flexipos\Sales\Features\UpdateSaleLine\UpdateSaleLine;
use Flexipos\Sales\Features\UpdateSaleLine\UpdateSaleLineHandler;
use Flexipos\Sales\Features\UpdateSaleLine\UpdateSaleLineResult;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 14 - UpdateSaleLine (AC-4, AC-6, AC-8, AC-47, AC-52)
|--------------------------------------------------------------------------
|
| The effective line is the stored one with the submitted fields laid over it,
| priced again through the real engine. Approval cases (a price override, a
| gated discount, AC-55) are step 15's file.
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
function uslWorld(): array
{
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TX10', '10.0000');
    $catalogue = SalesWorld::product('P1', '100.0000', $group);
    $case = PricingSeed::unit('CASE');
    SalesWorld::conversion($case, $catalogue['unit'], '24.000000');

    return ['till' => $till, 'sale' => SalesWorld::sale($till), 'product' => $catalogue['product'], 'unit' => $catalogue['unit'], 'case' => $case, 'group' => $group];
}

/**
 * @param  array<string, mixed>  $w
 */
function uslLine(array $w, string $quantity = '2', ?string $unit = null): AddSaleLineResult
{
    /** @var AddSaleLineHandler $handler */
    $handler = app(AddSaleLineHandler::class);

    return $handler->handle(new AddSaleLine(['sale_id' => $w['sale'], 'product_id' => $w['product'], 'unit_id' => $unit ?? $w['unit'], 'quantity' => $quantity], $w['till']['cashier']));
}

/**
 * @param  array<string, mixed>  $fields
 */
function uslUpdate(array $fields, ?string $actorId): UpdateSaleLineResult
{
    /** @var UpdateSaleLineHandler $handler */
    $handler = app(UpdateSaleLineHandler::class);

    return $handler->handle(new UpdateSaleLine($fields, $actorId));
}

it('re-prices the line at its new quantity, keeps its snapshots and number, and re-sums the header (AC-4, AC-8)', function () {
    $w = uslWorld();
    $line = uslLine($w);
    $other = uslLine($w, '1');

    $result = uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => '5'], $w['till']['cashier']);

    expect($result->lineTotal)->toBe('550.0000')
        ->and($result->saleTotal)->toBe('660.0000')
        ->and(SalesSeed::row('sale_items', $line->lineId))->toMatchArray([
            'line_no' => 1,
            'quantity' => '5.0000',
            'base_quantity' => '5.0000',
            'subtotal' => '500.0000',
            'tax_amount' => '50.0000',
            'line_total' => '550.0000',
        ])
        ->and(SalesSeed::row('sale_items', $other->lineId)['line_total'])->toEqual('110.0000')
        ->and(SalesSeed::row('sales', $w['sale']))->toMatchArray(['subtotal' => '600.0000', 'tax_total' => '60.0000', 'total' => '660.0000', 'balance_due' => '660.0000']);
});

it('re-prices into another unit of the same product: the factor and the base quantity follow', function () {
    $w = uslWorld();
    $line = uslLine($w, '1');

    uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'unit_id' => strtolower($w['case'])], $w['till']['cashier']);

    expect(SalesSeed::row('sale_items', $line->lineId))->toMatchArray([
        'unit_id' => $w['case'],
        'unit_code' => 'CASE',
        'unit_factor' => '24.000000',
        'base_quantity' => '24.0000',
        'line_total' => '2640.0000',
    ]);
});

it('leaves the product snapshots alone on a re-price', function () {
    $w = uslWorld();
    $line = uslLine($w);
    $name = SalesSeed::row('sale_items', $line->lineId)['product_name'];

    SalesSeed::connection()->table('products')->where('id', $w['product'])->update(['name' => 'Renamed']);

    uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => '3'], $w['till']['cashier']);

    expect(SalesSeed::row('sale_items', $line->lineId)['product_name'])->toBe($name);
});

it('refuses a quantity of zero or less as validation, and a changed line writes nothing on refusal (AC-52)', function (string $quantity) {
    $w = uslWorld();
    $line = uslLine($w);
    $before = SalesSeed::row('sale_items', $line->lineId);

    SalesRefusals::expect(SalesRefusal::Validation, fn () => uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => $quantity], $w['till']['cashier']));

    expect(SalesSeed::row('sale_items', $line->lineId))->toBe($before);
})->with(['0', '-1', '-0.5']);

it('refuses a line on a sale whose lines are locked, and a line that is not on the named sale', function () {
    $w = uslWorld();
    $line = uslLine($w);
    $otherSale = SalesWorld::sale($w['till']);

    // A line is found only through its own sale.
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => uslUpdate(['sale_id' => $otherSale, 'line_id' => $line->lineId, 'quantity' => '3'], $w['till']['cashier']));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => uslUpdate(['sale_id' => $w['sale'], 'line_id' => (string) Str::ulid(), 'quantity' => '3'], $w['till']['cashier']));

    SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['status' => 'pending_payment']);

    SalesRefusals::expect(SalesRefusal::SaleNotEditable, fn () => uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => '3'], $w['till']['cashier']));

    expect(SalesSeed::row('sale_items', $line->lineId)['quantity'])->toEqual('2.0000');
});

it('allows an edit while held from draft and refuses it while held from pending_payment (F-10)', function (string $heldFrom, bool $editable) {
    $w = uslWorld();
    $line = uslLine($w);

    SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['status' => 'held']);
    SalesSeed::connection()->table('sale_holds')->insert([
        'id' => (string) Str::ulid(), 'sale_id' => $w['sale'], 'action' => 'hold', 'from_status' => $heldFrom, 'to_status' => 'held',
        'terminal_id' => $w['till']['terminal'], 'employee_id' => $w['till']['cashier'], 'created_at' => SalesSeed::NOW,
    ]);

    $update = fn () => uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => '3'], $w['till']['cashier']);

    if ($editable) {
        expect($update()->lineTotal)->toBe('330.0000');

        return;
    }

    SalesRefusals::expect(SalesRefusal::SaleNotEditable, $update);
})->with(['from draft' => ['draft', true], 'from pending_payment' => ['pending_payment', false]]);

it('refuses a product that is no longer sellable, as an add does (AC-47)', function (string $column, mixed $value) {
    $w = uslWorld();
    $line = uslLine($w);

    SalesSeed::connection()->table('products')->where('id', $w['product'])->update([$column => $value]);

    SalesRefusals::expect(SalesRefusal::ProductNotSellable, fn () => uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => '3'], $w['till']['cashier']));

    expect(SalesSeed::row('sale_items', $line->lineId)['quantity'])->toEqual('2.0000');
})->with(['inactive' => ['is_active', 0], 'not sellable' => ['is_sellable', 0], 'variant parent' => ['type', 'variant_parent']]);

it('refuses an update that lost a race with another edit of the same line, rather than writing over it', function () {
    $w = uslWorld();
    $line = uslLine($w);

    // Another session changes the line's quantity after this one has read and priced it: the update runs right after
    // the transaction's sale lock (the second read of the sale; the first is the pre-read the price was computed from).
    $reads = 0;
    $raced = false;
    SalesSeed::connection()->listen(static function ($query) use (&$reads, &$raced, $line): void {
        if (str_starts_with($query->sql, 'select * from "sales" where "id" = ?') && ++$reads === 2) {
            $raced = true;
            SalesSeed::connection()->table('sale_items')->where('id', $line->lineId)->update(['quantity' => '9.0000']);
        }
    });

    SalesRefusals::expect(SalesRefusal::ReplayConflict, fn () => uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => '3'], $w['till']['cashier']));

    // (The rival here shares the refused transaction, so it rolls back with it; the refusal is the point.)
    expect($raced)->toBeTrue()
        ->and(array_column(SalesSeed::audit(), 'action'))->not->toContain('sales_sale_line_updated');
});

it('rejects fields it does not take: the product, the sale, a price, a label', function (string $field) {
    $w = uslWorld();
    $line = uslLine($w);

    SalesRefusals::expect(SalesRefusal::Validation, fn () => uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, $field => 'x'], $w['till']['cashier']));
})->with(['product_id', 'line_no', 'price_from_label', 'modifiers', 'unit_cost', 'tax_amount', 'line_total', 'employee_id']);

it('writes one audit row with the line before and after, and none for a refusal', function () {
    $w = uslWorld();
    $line = uslLine($w);
    SalesSeed::connection()->table('audit_logs')->delete();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => '0'], $w['till']['cashier']));

    expect(SalesSeed::audit())->toBe([]);

    uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => '3'], $w['till']['cashier']);

    $audit = SalesSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['action' => 'sales_sale_line_updated', 'subject_type' => 'sale_item', 'subject_id' => $line->lineId])
        ->and($audit['before']['quantity'])->toEqual('2.0000')
        ->and($audit['after']['quantity'])->toEqual('3.0000');
});

it('takes the sale FOR UPDATE as the first statement inside the transaction', function () {
    $w = uslWorld();
    $line = uslLine($w);

    $inside = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => uslUpdate(['sale_id' => $w['sale'], 'line_id' => $line->lineId, 'quantity' => '3'], $w['till']['cashier'])));

    expect($inside[0])->toContain('"sales"')->and($inside[0])->toContain(LockMarkingGrammar::FOR_UPDATE);
});

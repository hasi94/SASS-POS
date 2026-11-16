<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\AddSaleLine;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Catalog\ResolvedFactor;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Pricing\LineQuote;
use Flexipos\Contracts\Pricing\PricingEngine;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineResult;
use Flexipos\Sales\Tests\Support\FakePricingEngine;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 14 - AddSaleLine (AC-2 to AC-6, AC-8 to AC-10, AC-47, AC-51, AC-52)
|--------------------------------------------------------------------------
|
| Against the real UnitResolver and the real PricingEngine, seeded through
| T-029's seeds. The fake engine appears only where a refusal the real engine
| cannot produce is the point, and where what Sales passes in is pinned.
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
 * A till with a draft sale, a 10 percent exclusive tax group, a product at 100.0000 and a case of 24.
 *
 * @return array<string, string|array<string, string>>
 */
function aslWorld(): array
{
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TX10', '10.0000');
    $catalogue = SalesWorld::product('P1', '100.0000', $group);
    $case = PricingSeed::unit('CASE');
    SalesWorld::conversion($case, $catalogue['unit'], '24.000000');

    return [
        'till' => $till,
        'sale' => SalesWorld::sale($till),
        'product' => $catalogue['product'],
        'unit' => $catalogue['unit'],
        'case' => $case,
        'group' => $group,
    ];
}

/**
 * @param  array<string, mixed>  $fields
 */
function aslAdd(array $fields, ?string $actorId): AddSaleLineResult
{
    /** @var AddSaleLineHandler $handler */
    $handler = app(AddSaleLineHandler::class);

    return $handler->handle(new AddSaleLine($fields, $actorId));
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aslInput(array $w, array $overrides = []): array
{
    return array_merge(['sale_id' => $w['sale'], 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => '1'], $overrides);
}

/**
 * Every table the refusal must leave alone, as one comparable value.
 *
 * @return array<string, mixed>
 */
function aslSnapshot(): array
{
    $c = SalesSeed::connection();

    return [
        'sales' => $c->table('sales')->get()->map(fn ($r) => (array) $r)->all(),
        'lines' => $c->table('sale_items')->get()->map(fn ($r) => (array) $r)->all(),
        'approvals' => $c->table('approval_requests')->get()->map(fn ($r) => (array) $r)->all(),
        'audit' => SalesSeed::audit(),
    ];
}

it('adds a line in the unit asked for: a case of 24 carries factor 24.000000, base 24 and the unit code (AC-2)', function () {
    $w = aslWorld();

    $result = aslAdd(aslInput($w, ['unit_id' => $w['case']]), $w['till']['cashier']);

    expect($result->lineNo)->toBe(1)
        ->and(SalesSeed::row('sale_items', $result->lineId))->toMatchArray([
            'sale_id' => $w['sale'],
            'line_no' => 1,
            'product_id' => $w['product'],
            'unit_id' => $w['case'],
            'unit_code' => 'CASE',
            'quantity' => '1.0000',
            'unit_factor' => '24.000000',
            'base_quantity' => '24.0000',
            'list_price' => '2400.0000',
            'unit_price' => '2400.0000',
            'subtotal' => '2400.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '240.0000',
            'line_total' => '2640.0000',
            'price_from_label' => 0,
            'modifiers' => null,
            'unit_cost' => null,
            'line_cost' => null,
            'refunded_quantity' => '0.0000',
            'refund_of_item_id' => null,
            'price_overridden_by' => null,
        ]);
});

it('snapshots the product name, sku, category and brand, so a later rename leaves the line as it was (AC-2)', function () {
    $w = aslWorld();

    $result = aslAdd(aslInput($w), $w['till']['cashier']);

    $product = SalesSeed::row('products', $w['product']);

    SalesSeed::connection()->table('products')->where('id', $w['product'])->update(['name' => 'Renamed', 'sku' => 'RENAMED']);

    expect(SalesSeed::row('sale_items', $result->lineId))->toMatchArray([
        'product_name' => $product['name'],
        'sku' => 'P1',
        'category_id' => $product['category_id'],
        'brand_id' => null,
    ]);
});

it('numbers lines from 1 and counts on past a removed one', function () {
    $w = aslWorld();

    $first = aslAdd(aslInput($w), $w['till']['cashier']);
    $second = aslAdd(aslInput($w), $w['till']['cashier']);
    SalesSeed::connection()->table('sale_items')->where('id', $first->lineId)->delete();
    $third = aslAdd(aslInput($w), $w['till']['cashier']);

    expect([$first->lineNo, $second->lineNo, $third->lineNo])->toBe([1, 2, 3]);
});

it('refuses a unit that does not convert to the stock unit as unit_unresolvable, naming both units (AC-3)', function () {
    $w = aslWorld();
    $weight = PricingSeed::unit('KG', 'mass');
    $before = aslSnapshot();

    $refused = SalesRefusals::expect(SalesRefusal::UnitUnresolvable, fn () => aslAdd(aslInput($w, ['unit_id' => $weight]), $w['till']['cashier']));

    expect($refused->getMessage())->toContain($weight)->and($refused->getMessage())->toContain($w['unit'])
        ->and($refused->cause)->not->toBeNull()
        ->and(aslSnapshot())->toBe($before);
});

it('stores every priced column exactly as the engine returned it, and nothing it did not (AC-4, R-11)', function () {
    $w = aslWorld();
    $result = aslAdd(aslInput($w, ['unit_id' => $w['case'], 'quantity' => '2']), $w['till']['cashier']);

    // The same quote asked for directly: the stored line is the engine's answer, byte for byte.
    $snapshot = app(UnitResolver::class)->snapshot($w['product'], $w['case'], '2.0000');
    $priced = app(PricingEngine::class)->quoteLine(new LineQuote($w['product'], $snapshot, '2026-11-12T10:00:00.000Z', '2026-11-12', 'LKR', null, null, $w['till']['branch']));

    $row = SalesSeed::row('sale_items', $result->lineId);

    expect($row)->toMatchArray([
        'quantity' => $snapshot->quantity,
        'unit_factor' => $snapshot->unitFactor,
        'base_quantity' => $snapshot->baseQuantity,
        'list_price' => $priced->listPrice,
        'unit_price' => $priced->unitPrice,
        'subtotal' => $priced->subtotal,
        'discount_amount' => $priced->discountAmount,
        'tax_group_id' => $priced->taxGroupId,
        'tax_amount' => $priced->tax->total,
        'line_total' => $priced->lineTotal,
        'discount_percent' => null,
    ])
        ->and(json_decode((string) $row['tax_breakdown'], true))->toBe($priced->tax->toArray())
        ->and($row['tax_amount'])->not->toBe('0.0000');
});

it('asks the engine with the sale\'s instant, business date, currency, customer, the customer\'s group and the branch (R-10)', function () {
    $w = aslWorld();
    $group = PricingSeed::customerGroup('VIP');
    $customer = SalesWorld::customer('Ada', ['group_id' => $group]);
    SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['customer_id' => $customer]);

    $fake = FakePricingEngine::install();

    aslAdd(aslInput($w), $w['till']['cashier']);

    expect($fake->quotes)->toHaveCount(1);

    $quote = $fake->quotes[0];

    expect($quote->at)->toBe('2026-11-12T10:00:00.000Z')
        ->and($quote->documentDate)->toBe('2026-11-12')
        ->and($quote->currency)->toBe('LKR')
        ->and($quote->customerId)->toBe($customer)
        ->and($quote->customerGroupId)->toBe($group)
        ->and($quote->branchId)->toBe($w['till']['branch'])
        ->and($quote->productId)->toBe($w['product'])
        ->and($quote->discountId)->toBeNull();
});

it('refuses a malformed product, unit, discount or sale id as validation, never a raw error (R-10)', function (string $field, mixed $value) {
    $w = aslWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => aslAdd(aslInput($w, [$field => $value]), $w['till']['cashier']));
})->with([
    'product' => ['product_id', 'not-a-ulid'],
    'unit' => ['unit_id', 'short'],
    'discount' => ['discount_id', 'zzzzzzzzzzzzzzzzzzzzzzzzzz-'],
    'sale' => ['sale_id', 42],
    'approval' => ['approval_id', 'x'],
    'salesperson' => ['employee_id', 'x'],
]);

it('refuses no price as unresolvable_price, through the fake engine only: the real one cannot raise it (R-3, AC-5)', function () {
    $w = aslWorld();
    FakePricingEngine::install(PricingRefused::noPriceResolved('no price'));
    $before = aslSnapshot();

    $refused = SalesRefusals::expect(SalesRefusal::UnresolvablePrice, fn () => aslAdd(aslInput($w), $w['till']['cashier']));

    expect($refused->cause)->toBe(PricingRefusal::NoPriceResolved)
        ->and($refused->code())->toBe('unresolvable_price')
        ->and(aslSnapshot())->toBe($before);
});

it('carries every other engine refusal under its own name, with the PricingRefusal as the cause (AC-5, Ruling 8 amendment)', function (string $constructor, PricingRefusal $reason) {
    $w = aslWorld();
    FakePricingEngine::install(PricingRefused::{$constructor}('refused'));
    $before = aslSnapshot();

    $refused = SalesRefusals::expect(SalesRefusal::PricingRefused, fn () => aslAdd(aslInput($w), $w['till']['cashier']));

    expect($refused->cause)->toBe($reason)
        ->and($refused->code())->toBe($reason->value)
        ->and(aslSnapshot())->toBe($before);
})->with([
    'unknown_tax_group' => ['unknownTaxGroup', PricingRefusal::UnknownTaxGroup],
    'no_effective_rate' => ['noEffectiveRate', PricingRefusal::NoEffectiveRate],
    'inclusive_amount_too_small' => ['inclusiveAmountTooSmall', PricingRefusal::InclusiveAmountTooSmall],
    'unknown_discount' => ['unknownDiscount', PricingRefusal::UnknownDiscount],
    'discount_scope_mismatch' => ['discountScopeMismatch', PricingRefusal::DiscountScopeMismatch],
    'inactive' => ['inactive', PricingRefusal::Inactive],
    'not_yet_started' => ['notYetStarted', PricingRefusal::NotYetStarted],
    'expired' => ['expired', PricingRefusal::Expired],
    'usage_limit_reached' => ['usageLimitReached', PricingRefusal::UsageLimitReached],
    'discount_not_allowed' => ['discountNotAllowed', PricingRefusal::DiscountNotAllowed],
    'not_applicable' => ['notApplicable', PricingRefusal::NotApplicable],
    'below_min_amount' => ['belowMinAmount', PricingRefusal::BelowMinAmount],
    'unsupported_discount_type' => ['unsupportedDiscountType', PricingRefusal::UnsupportedDiscountType],
    'invalid_amount' => ['invalidAmount', PricingRefusal::InvalidAmount],
    'out_of_range' => ['outOfRange', PricingRefusal::OutOfRange],
]);

it('surfaces a real engine refusal under its own name: a discount that does not exist is unknown_discount (AC-5)', function () {
    $w = aslWorld();

    $refused = SalesRefusals::expect(SalesRefusal::PricingRefused, fn () => aslAdd(aslInput($w, ['discount_id' => (string) Str::ulid()]), $w['till']['cashier']));

    expect($refused->cause)->toBe(PricingRefusal::UnknownDiscount)->and($refused->code())->toBe('unknown_discount');
});

it('accepts a product nobody priced, quoting 0.0000 from the product price, pending owner question Q-2 (FP-440, R-3)', function () {
    $w = aslWorld();
    $free = SalesWorld::product('FREE', '0.0000', $w['group']);

    $result = aslAdd(aslInput($w, ['product_id' => $free['product'], 'unit_id' => $free['unit']]), $w['till']['cashier']);

    expect(SalesSeed::row('sale_items', $result->lineId))->toMatchArray(['unit_price' => '0.0000', 'subtotal' => '0.0000', 'line_total' => '0.0000'])
        ->and($result->saleTotal)->toBe('0.0000');
});

it('refuses a quantity of zero or less as validation before the resolver is called (AC-52, R-14)', function (mixed $quantity) {
    $w = aslWorld();

    $spy = new class implements UnitResolver
    {
        public int $calls = 0;

        public function resolve(string $productId, string $fromUnitId): ResolvedFactor
        {
            $this->calls++;
            throw UnitResolutionRefused::unknownUnit('spy');
        }

        public function snapshot(string $productId, string $unitId, int|string $quantity): UnitSnapshot
        {
            $this->calls++;
            throw UnitResolutionRefused::unknownUnit('spy');
        }

        public function snapshotInherited(string $productId, string $referencedUnitId, string $referencedUnitFactor, string $unitId, int|string $quantity): UnitSnapshot
        {
            $this->calls++;
            throw UnitResolutionRefused::unknownUnit('spy');
        }
    };
    app()->instance(UnitResolver::class, $spy);

    SalesRefusals::expect(SalesRefusal::Validation, fn () => aslAdd(aslInput($w, ['quantity' => $quantity]), $w['till']['cashier']));

    expect($spy->calls)->toBe(0);
})->with(['zero' => ['0'], 'zero with decimals' => ['0.0000'], 'minus one' => ['-1'], 'minus a half' => ['-0.5'], 'a float' => [1.5], 'text' => ['abc'], 'five decimals' => ['1.00001']]);

it('refuses a quantity that rounds to zero in its unit as validation', function () {
    $w = aslWorld();
    $whole = PricingSeed::unit('WHOLE', 'count', ['precision' => 0]);
    $product = SalesWorld::product('P2', '5.0000', $w['group'], ['stock_unit_id' => $whole]);

    SalesRefusals::expect(SalesRefusal::Validation, fn () => aslAdd(aslInput($w, ['product_id' => $product['product'], 'unit_id' => $whole, 'quantity' => '0.4']), $w['till']['cashier']));
});

it('refuses a product that is not sellable, naming the fact, and writes nothing (AC-47, D-57)', function (string $column, mixed $value, string $fact) {
    $w = aslWorld();
    $product = SalesWorld::product('P3', '5.0000', $w['group'], [$column => $value]);
    $before = aslSnapshot();

    $refused = SalesRefusals::expect(SalesRefusal::ProductNotSellable, fn () => aslAdd(aslInput($w, ['product_id' => $product['product'], 'unit_id' => $product['unit']]), $w['till']['cashier']));

    expect($refused->getMessage())->toContain($fact)->and(aslSnapshot())->toBe($before);
})->with([
    'inactive' => ['is_active', false, 'not active'],
    'not sellable' => ['is_sellable', false, 'not sellable'],
    'a variant parent' => ['type', 'variant_parent', 'variant parent'],
]);

it('refuses a soft-deleted product under the engine\'s own reason, unknown_product (AC-53)', function () {
    $w = aslWorld();
    SalesSeed::connection()->table('products')->where('id', $w['product'])->update(['deleted_at' => SalesSeed::NOW]);

    $refused = SalesRefusals::expect(SalesRefusal::PricingRefused, fn () => aslAdd(aslInput($w), $w['till']['cashier']));

    expect($refused->code())->toBe('unknown_product');
});

it('refuses a sale that does not exist as not_found', function () {
    $w = aslWorld();

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => aslAdd(aslInput($w, ['sale_id' => (string) Str::ulid()]), $w['till']['cashier']));
});

it('refuses a line on a sale whose lines are locked: pending_payment and held from pending_payment, but not draft or held from draft (AC-6, F-10)', function (string $status, ?string $heldFrom, bool $editable) {
    $w = aslWorld();
    SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['status' => $status]);

    if ($heldFrom !== null) {
        SalesSeed::connection()->table('sale_holds')->insert([
            'id' => (string) Str::ulid(), 'sale_id' => $w['sale'], 'action' => 'hold', 'from_status' => $heldFrom, 'to_status' => 'held',
            'terminal_id' => $w['till']['terminal'], 'employee_id' => $w['till']['cashier'], 'created_at' => SalesSeed::NOW,
        ]);
    }

    if ($editable) {
        expect(aslAdd(aslInput($w), $w['till']['cashier'])->lineNo)->toBe(1);

        return;
    }

    $before = aslSnapshot();

    SalesRefusals::expect(SalesRefusal::SaleNotEditable, fn () => aslAdd(aslInput($w), $w['till']['cashier']));

    expect(aslSnapshot())->toBe($before);
})->with([
    'draft' => ['draft', null, true],
    'pending_payment' => ['pending_payment', null, false],
    'held from draft' => ['held', 'draft', true],
    'held from pending_payment' => ['held', 'pending_payment', false],
    'completed' => ['completed', null, false],
    'voided' => ['voided', null, false],
]);

it('refuses a line on a refund document', function () {
    $w = aslWorld();
    SalesSeed::connection()->table('sales')->where('id', $w['sale'])->update(['type' => 'refund']);

    SalesRefusals::expect(SalesRefusal::SaleNotEditable, fn () => aslAdd(aslInput($w), $w['till']['cashier']));
});

it('re-sums the header from the lines after every add: subtotal, discount, tax and total, with balance due (AC-8)', function () {
    $w = aslWorld();

    $first = aslAdd(aslInput($w, ['unit_id' => $w['case']]), $w['till']['cashier']);
    $second = aslAdd(aslInput($w, ['quantity' => '3']), $w['till']['cashier']);

    expect($first->saleTotal)->toBe('2640.0000')
        ->and($second->lineTotal)->toBe('330.0000')
        ->and($second->saleTotal)->toBe('2970.0000')
        ->and(SalesSeed::row('sales', $w['sale']))->toMatchArray([
            'subtotal' => '2700.0000',
            'discount_total' => '0.0000',
            'tax_total' => '270.0000',
            'total' => '2970.0000',
            'balance_due' => '2970.0000',
            'service_charge' => '0.0000',
            'rounding' => '0.0000',
            'stock_applied' => 0,
            'cost_total' => null,
        ]);
});

it('refuses the fields that are never inputs: label price, modifiers, costs, snapshots and sync fields (AC-9, AC-10)', function (string $field) {
    $w = aslWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => aslAdd(aslInput($w, [$field => 'x']), $w['till']['cashier']));
})->with([
    'price_from_label', 'modifiers', 'unit_cost', 'line_cost', 'cost_total', 'discount_percent', 'kitchen_station_id', 'batch_id', 'serial_id',
    'product_name', 'sku', 'unit_factor', 'base_quantity', 'tax_amount', 'line_total', 'line_no', 'refund_of_item_id', 'price_overridden_by', 'refunded_quantity',
    'device_id', 'client_created_at', 'origin',
]);

it('validates the line salesperson against the employee directory (AC-2, F-24)', function () {
    $w = aslWorld();
    $seller = SalesSeed::employee('seller');

    $result = aslAdd(aslInput($w, ['employee_id' => strtolower($seller)]), $w['till']['cashier']);

    expect(SalesSeed::row('sale_items', $result->lineId)['employee_id'])->toBe($seller);

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => aslAdd(aslInput($w, ['employee_id' => (string) Str::ulid()]), $w['till']['cashier']));
});

it('needs a live employee as the actor', function () {
    $w = aslWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => aslAdd(aslInput($w), null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => aslAdd(aslInput($w), (string) Str::ulid()));
});

it('writes one audit row for the line, and none for a refusal', function () {
    $w = aslWorld();

    $result = aslAdd(aslInput($w), $w['till']['cashier']);

    expect(SalesSeed::onlyAuditRow())->toMatchArray([
        'module' => 'sales',
        'action' => 'sales_sale_line_added',
        'subject_type' => 'sale_item',
        'subject_id' => $result->lineId,
        'actor_id' => $w['till']['cashier'],
        'before' => null,
        'branch_id' => $w['till']['branch'],
    ]);
});

it('takes the sale FOR UPDATE as the first statement inside the transaction (Ruling 7)', function () {
    $w = aslWorld();

    $log = SalesQueryLog::during(fn () => aslAdd(aslInput($w), $w['till']['cashier']));
    $inside = SalesQueryLog::inTransaction($log);

    expect($inside[0])->toContain('"sales"')->and($inside[0])->toContain(LockMarkingGrammar::FOR_UPDATE);

    // And everything the engine and the resolver read ran before the transaction opened.
    $begin = array_search(SalesQueryLog::BEGIN, $log, true);
    $before = array_slice($log, 0, (int) $begin);

    expect(array_filter($before, static fn (string $sql): bool => str_contains($sql, '"products"') || str_contains($sql, '"units"')))->not->toBe([]);
});

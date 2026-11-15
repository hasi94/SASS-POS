<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Engine;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Pricing\BillQuote;
use Flexipos\Contracts\Pricing\LineQuote;
use Flexipos\Contracts\Pricing\PricingEngine;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\TaxContext;
use Flexipos\Contracts\Pricing\TaxQuote;
use Flexipos\Pricing\Engine\DatabasePricingEngine;
use Flexipos\Pricing\Tests\Support\Build;
use Flexipos\Pricing\Tests\Support\FixtureDatabase;
use Flexipos\Pricing\Tests\Support\FixtureReplay;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-029 plan step 16 - the database adapter (AC-15 to AC-17, AC-22, AC-30.4)
|--------------------------------------------------------------------------
|
| The adapter reads rows and normalises them; the pure engine decides. This
| file proves the first half of that sentence from the outside:
|
|  - the rows a checked-in fixture stands for, written to the stand-in
|    database, come back from the adapter as exactly the fixture's `expect`
|    (the fixture file stays the single source of the answer);
|  - what the adapter passes through unfiltered (a deleted or inactive list, an
|    inactive member tax) is skipped by the engine, never by the adapter;
|  - ids, decimals and instants are normalised;
|  - four promotion codes, inserted in the reverse of the order a bytewise
|    comparison puts them in, still come out in that order (D-29).
|
| Honest limit: the SQLite stand-in's default collation is already bytewise, so
| this file cannot show a collation hazard by itself. It proves that nothing in
| the adapter relies on fetch order, and that the engine's tie-break is what
| decides. The MySQL half is PricingSchemaEngineTest, held with ENGINE_HOLD.
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

function dpeEngine(): PricingEngine
{
    return app(PricingEngine::class);
}

/**
 * @return array<string, mixed>
 */
function dpeFixture(string $kind, string $name): array
{
    $path = FixtureReplay::directory().'/'.$kind.'/'.$name.'.json';

    /** @var array<string, mixed> $case */
    $case = json_decode((string) file_get_contents($path), true);

    return $case;
}

/**
 * The line quote a fixture's input asks for: a snapshot of exactly the unit
 * facts the fixture's ladder names.
 *
 * @param  array<string, mixed>  $ladder
 */
function dpeQuote(array $ladder, string $productId, string $quantity, string $documentDate, ?string $discountId = null): LineQuote
{
    return new LineQuote(
        $productId,
        new UnitSnapshot((string) $ladder['unit_id'], 'unit', $quantity, (string) $ladder['unit_factor'], (string) $ladder['base_quantity'], FactorSource::StockUnit),
        (string) $ladder['at'],
        $documentDate,
        (string) $ladder['currency'],
        $ladder['customer_id'] === null ? null : (string) $ladder['customer_id'],
        $ladder['customer_group_id'] === null ? null : (string) $ladder['customer_group_id'],
        $ladder['branch_id'] === null ? null : (string) $ladder['branch_id'],
        $discountId,
    );
}

/**
 * Seed a `line` fixture and quote it through the adapter.
 *
 * @param  array<string, mixed>  $input  the fixture's input (seeded), possibly altered
 * @return array<string, mixed>
 */
function dpeQuoteLine(array $input): array
{
    FixtureDatabase::seedLine($input);

    /** @var array<string, mixed> $ladder */
    $ladder = $input['ladder'];
    /** @var array<string, mixed> $product */
    $product = $input['product'];

    $priced = dpeEngine()->quoteLine(dpeQuote(
        $ladder,
        (string) $product['id'],
        (string) $input['quantity'],
        (string) $input['document_date'],
        $input['discount_id'] === null ? null : (string) $input['discount_id'],
    ));

    return dpeStringify($priced->toArray());
}

function dpeStringify(mixed $value): mixed
{
    if (is_int($value)) {
        return (string) $value;
    }

    return is_array($value) ? array_map(dpeStringify(...), $value) : $value;
}

/**
 * @return array<string, mixed>
 */
function dpeInput(string $kind, string $name): array
{
    /** @var array<string, mixed> $input */
    $input = dpeFixture($kind, $name)['input'];

    return $input;
}

/**
 * A product with a stock unit, an empty tax group, and a hand-built stock-unit
 * snapshot, for the cases that need no fixture.
 *
 * @return array{product: string, unit: string, group: string, snapshot: UnitSnapshot}
 */
function dpeBareProduct(string $sku = 'BARE'): array
{
    $unit = PricingSeed::unit('U'.$sku);
    $group = PricingSeed::group('G'.$sku);
    $product = PricingSeed::product($sku, $unit, ['tax_group_id' => $group, 'selling_price' => '1.0000']);

    return [
        'product' => $product,
        'unit' => $unit,
        'group' => $group,
        'snapshot' => new UnitSnapshot($unit, 'each', '1.0000', '1.000000', '1.0000', FactorSource::StockUnit),
    ];
}

/**
 * @param  array{product: string, unit: string, group: string, snapshot: UnitSnapshot}  $bare
 */
function dpeBareQuote(array $bare, ?string $customerId = null, ?string $discountId = null): LineQuote
{
    return new LineQuote($bare['product'], $bare['snapshot'], Build::AT, Build::DATE, 'USD', $customerId, null, null, $discountId);
}

// ----------------------------------------------------------------- the binding

it('resolves the published contract to the database adapter, as a singleton', function () {
    expect(app(PricingEngine::class))->toBeInstanceOf(DatabasePricingEngine::class)
        ->and(app(PricingEngine::class))->toBe(app(PricingEngine::class));
});

// ------------------------------------------------- the adapter adds nothing

it('returns exactly the fixture expectation for a unit-matched case line with a discount', function () {
    $case = dpeFixture('line', 'line-case-of-24-with-discount-exclusive');

    expect(dpeQuoteLine((array) $case['input']))->toBe($case['expect']);
});

it('returns exactly the fixture expectation for a case derived from the stock price', function () {
    $case = dpeFixture('line', 'line-three-cases-derived-from-the-stock-price');

    expect(dpeQuoteLine((array) $case['input']))->toBe($case['expect']);
});

it('returns exactly the fixture expectation for a composed inclusive line', function () {
    $case = dpeFixture('line', 'line-inclusive-with-discount');

    expect(dpeQuoteLine((array) $case['input']))->toBe($case['expect']);
});

it('returns the fixture expectation for a promotion that applies at its branch', function () {
    $case = dpeFixture('price', 'price-branch-promotion-applies-at-its-branch');
    $input = (array) $case['input'];
    $expect = (array) $case['expect'];

    FixtureDatabase::seedLine($input, true);

    $priced = dpeStringify(dpeEngine()->quoteLine(dpeQuote($input, FixtureDatabase::PRODUCT, '1.0000', Build::DATE))->toArray());

    expect($priced['list_price'])->toBe($expect['list_price'])
        ->and($priced['list_discount_percent'])->toBe($expect['discount_percent'])
        ->and($priced['unit_price'])->toBe($expect['unit_price'])
        ->and($priced['source'])->toBe($expect['source']);
});

it('returns the fixture expectation for the branch selling price (step 5), read from product_branches', function () {
    $case = dpeFixture('price', 'price-step-5-branch-selling-price-wins');
    $input = (array) $case['input'];
    $expect = (array) $case['expect'];

    FixtureDatabase::seedLine($input, true);

    $priced = dpeStringify(dpeEngine()->quoteLine(dpeQuote($input, FixtureDatabase::PRODUCT, '1.0000', Build::DATE))->toArray());

    expect($priced['unit_price'])->toBe($expect['unit_price'])
        ->and($priced['source'])->toBe($expect['source']);
});

it('returns exactly the fixture expectation for a bill discount', function () {
    $case = dpeFixture('bill', 'bill-percentage-capped');
    $input = (array) $case['input'];

    FixtureDatabase::seedBill($input);

    $bill = dpeEngine()->billDiscount(new BillQuote((string) $input['discount_id'], (string) $input['at'], array_map('strval', (array) $input['discounted_subtotals'])));

    expect($bill->toArray())->toBe($case['expect']);
});

it('prices a snapshot obtained from the real UnitResolver as given: two cases at 179 each', function () {
    $each = PricingSeed::unit('each');
    $case = PricingSeed::unit('case', 'count', ['base_unit_id' => $each, 'factor' => '24.000000']);
    $group = PricingSeed::group('G0');
    $product = PricingSeed::product('P1', $each, ['tax_group_id' => $group]);
    $list = PricingSeed::priceList('RETAIL');
    $customer = PricingSeed::customer('Ann');

    PricingSeed::item($list, $product, $case, '1.0000', '179.0000');
    PricingSeed::assignment($list, 'customer', $customer);

    $snapshot = app(UnitResolver::class)->snapshot($product, $case, 2);

    $priced = dpeEngine()->quoteLine(new LineQuote($product, $snapshot, Build::AT, Build::DATE, 'USD', $customer));

    expect($snapshot->unitFactor)->toBe('24.000000')
        ->and($snapshot->baseQuantity)->toBe('48.0000')
        ->and($priced->unitPrice)->toBe('179.0000')
        ->and($priced->subtotal)->toBe('358.0000')
        ->and($priced->lineTotal)->toBe('358.0000');
});

// ------------------------------------------------------------- the refusals

it('refuses an unknown product and a soft-deleted product as unknown_product', function () {
    $bare = dpeBareProduct();

    expect(Build::reasonOf(fn () => dpeEngine()->quoteLine(new LineQuote(Build::id('NOSUCHPRODUCT'), $bare['snapshot'], Build::AT, Build::DATE, 'USD'))))->toBe('unknown_product');

    // Control: the same quote against the live product prices.
    expect(dpeEngine()->quoteLine(dpeBareQuote($bare))->unitPrice)->toBe('1.0000');

    PricingSeed::connection()->table('products')->where('id', $bare['product'])->update(['deleted_at' => PricingSeed::NOW]);

    expect(Build::reasonOf(fn () => dpeEngine()->quoteLine(dpeBareQuote($bare))))->toBe('unknown_product');
});

it('refuses an unknown tax group given to computeTax, and a product whose tax group is null', function () {
    $quote = new TaxQuote(Build::id('NOSUCHGROUP'), '100.0000', '1.0000', Build::DATE, TaxContext::Sale);

    expect(Build::reasonOf(fn () => dpeEngine()->computeTax($quote)))->toBe('unknown_tax_group');

    $bare = dpeBareProduct();

    PricingSeed::connection()->table('products')->where('id', $bare['product'])->update(['tax_group_id' => null]);

    expect(Build::reasonOf(fn () => dpeEngine()->quoteLine(dpeBareQuote($bare))))->toBe('unknown_tax_group');
});

it('refuses an unknown discount, and a soft-deleted discount, as unknown_discount', function () {
    $bare = dpeBareProduct();
    $discount = PricingSeed::discount('TEN');

    expect(Build::reasonOf(fn () => dpeEngine()->quoteLine(dpeBareQuote($bare, null, Build::id('NOSUCHDISCOUNT')))))->toBe('unknown_discount');

    // Control: the live discount applies.
    expect(dpeEngine()->quoteLine(dpeBareQuote($bare, null, $discount))->discountAmount)->toBe('0.1000');

    PricingSeed::connection()->table('discounts')->where('id', $discount)->update(['deleted_at' => PricingSeed::NOW]);

    expect(Build::reasonOf(fn () => dpeEngine()->quoteLine(dpeBareQuote($bare, null, $discount))))->toBe('unknown_discount');

    $bill = new BillQuote($discount, Build::AT, ['10.0000']);

    expect(Build::reasonOf(fn () => dpeEngine()->billDiscount($bill)))->toBe('unknown_discount');
});

// ------------------------------------------- what the adapter passes through

it('passes a deleted list, an inactive list and an inactive member tax to the engine, which skips them', function () {
    $case = dpeFixture('line', 'line-case-of-24-with-discount-exclusive');
    $input = (array) $case['input'];
    $customer = (string) ((array) $input['ladder'])['customer_id'];

    FixtureDatabase::seedLine($input);

    $product = (string) ((array) $input['product'])['id'];
    $case24 = (string) ((array) $input['ladder'])['unit_id'];

    // Two cheaper lists on the same customer at a higher priority: if either
    // reached the price, the answer would differ from the fixture's.
    foreach (['DELETED' => ['deleted_at' => PricingSeed::NOW], 'INACTIVE' => ['is_active' => false]] as $code => $flags) {
        $list = PricingSeed::priceList($code, ['priority' => 9, ...$flags]);

        PricingSeed::item($list, $product, $case24, '1.0000', '1.0000');
        PricingSeed::assignment($list, 'customer', $customer, 9);
    }

    // An inactive 50 % tax in the group, which would change the total if used.
    $authority = PricingSeed::authority('PASS');
    $tax = PricingSeed::tax($authority, 'OFF', ['is_active' => false]);

    PricingSeed::rate($tax, '50.0000', '2020-01-01');
    PricingSeed::member((string) ((array) $input['tax_group'])['id'], $tax, 9);

    $priced = dpeStringify(dpeEngine()->quoteLine(dpeQuote(
        (array) $input['ladder'],
        $product,
        (string) $input['quantity'],
        (string) $input['document_date'],
        (string) $input['discount_id'],
    ))->toArray());

    expect($priced)->toBe($case['expect']);
});

it('accepts a list whose window is exactly the quote instant, read back from DATETIME(3)', function () {
    $case = dpeFixture('line', 'line-case-of-24-with-discount-exclusive');
    $input = (array) $case['input'];
    $input['ladder'] = (array) $input['ladder'];
    $at = (string) $input['ladder']['at'];

    // The list's window is the single instant of the quote: accepted exactly there.
    $inside = $input;
    $inside['ladder']['lists'] = [[...(array) ((array) $inside['ladder']['lists'])[0], 'starts_at' => $at, 'ends_at' => $at]];

    expect(dpeQuoteLine($inside))->toBe($case['expect']);
});

it('skips a list whose window closed one millisecond before the quote (control: the window is really read)', function () {
    $case = dpeFixture('line', 'line-case-of-24-with-discount-exclusive');
    $input = (array) $case['input'];
    $input['ladder'] = (array) $input['ladder'];
    $input['ladder']['selling_price'] = '1.0000';
    $input['ladder']['lists'] = [[...(array) ((array) $input['ladder']['lists'])[0], 'ends_at' => '2025-06-15T09:29:59.999Z']];

    $priced = dpeQuoteLine($input);

    // Step 6: the product price 1.00 derived by the factor 24.
    expect($priced['unit_price'])->toBe('24.0000')
        ->and($priced['source'])->toBe(['step' => '6', 'price_list_id' => null, 'basis' => 'product']);
});

// ------------------------------------------------------------ normalisation

it('matches an item unit and an assignment scope stored in lower case (K-5), and a window stored as DATETIME(3)', function () {
    $case = dpeFixture('line', 'line-case-of-24-with-discount-exclusive');
    $input = (array) $case['input'];
    $quoted = $input;

    // Store the unit id and the customer scope id in lower case; quote with the
    // upper-case ids the contract's shape check produces.
    $input['ladder'] = (array) $input['ladder'];
    $input['ladder']['unit_id'] = strtolower((string) $input['ladder']['unit_id']);

    $lists = (array) $input['ladder']['lists'];
    $list = (array) $lists[0];
    $list['items'] = array_map(static fn (mixed $item): array => [...(array) $item, 'unit_id' => strtolower((string) ((array) $item)['unit_id'])], (array) $list['items']);
    $list['assignments'] = array_map(static fn (mixed $assignment): array => [...(array) $assignment, 'scope_id' => strtolower((string) ((array) $assignment)['scope_id'])], (array) $list['assignments']);
    $input['ladder']['lists'] = [$list];

    FixtureDatabase::seedLine($input);

    $stored = PricingSeed::connection()->table('price_list_assignments')->value('scope_id');

    expect($stored)->toBe(strtolower((string) $stored))
        ->and($stored)->not->toBe(strtoupper((string) $stored));

    $priced = dpeStringify(dpeEngine()->quoteLine(dpeQuote(
        (array) $quoted['ladder'],
        (string) ((array) $quoted['product'])['id'],
        (string) $quoted['quantity'],
        (string) $quoted['document_date'],
        (string) $quoted['discount_id'],
    ))->toArray());

    expect($priced)->toBe($case['expect']);
});

// ------------------------------------------------ K-3: the group it is given

it('computeTax uses the group it is given and never looks at a product (K-3)', function () {
    $authority = PricingSeed::authority('K3');
    $saleTax = PricingSeed::tax($authority, 'SALEONLY', ['applies_to' => 'sale']);
    $purchaseTax = PricingSeed::tax($authority, 'BUYONLY', ['applies_to' => 'purchase']);

    PricingSeed::rate($saleTax, '5.0000', '2020-01-01');
    PricingSeed::rate($purchaseTax, '10.0000', '2020-01-01');

    $groupX = PricingSeed::group('GX');
    $groupY = PricingSeed::group('GY');

    PricingSeed::member($groupX, $saleTax, 1);
    PricingSeed::member($groupX, $purchaseTax, 2);
    PricingSeed::member($groupY, $saleTax, 1);

    // A product whose two columns name different groups: neither is consulted.
    $unit = PricingSeed::unit('UK3');

    PricingSeed::product('K3', $unit, ['tax_group_id' => $groupY, 'purchase_tax_group_id' => $groupY]);

    $purchase = dpeEngine()->computeTax(new TaxQuote($groupX, '100.0000', '1.0000', Build::DATE, TaxContext::Purchase));
    $sale = dpeEngine()->computeTax(new TaxQuote($groupX, '100.0000', '1.0000', Build::DATE, TaxContext::Sale));

    expect($purchase->total)->toBe('10.0000')
        ->and(array_map(static fn ($line): string => $line->code, $purchase->lines))->toBe(['BUYONLY'])
        ->and($sale->total)->toBe('5.0000')
        ->and(array_map(static fn ($line): string => $line->code, $sale->lines))->toBe(['SALEONLY']);
});

// ------------------------------------------------- D-29: bytewise ties, end to end

/**
 * Four unassigned promotion lists on one product, equal priority, inserted in
 * this order, so that the order the rows come back in (insertion, and the id
 * order of the key) is the reverse of the bytewise order `10`, `9`, `B`, `a`.
 *
 * @return array{product: string, snapshot: UnitSnapshot, lists: array<int|string, string>}
 */
function dpePromotionCodes(): array
{
    $bare = dpeBareProduct('TIES');
    $lists = [];

    foreach (['a' => '70.0000', 'B' => '80.0000', '9' => '90.0000', '10' => '100.0000'] as $code => $price) {
        $list = PricingSeed::priceList((string) $code, ['type' => 'promotion']);

        PricingSeed::item($list, $bare['product'], null, '1.0000', $price);

        $lists[(string) $code] = $list;
    }

    return ['product' => $bare['product'], 'snapshot' => $bare['snapshot'], 'lists' => $lists];
}

it('orders equal-priority promotions bytewise, whatever order the rows are fetched in', function () {
    $seed = dpePromotionCodes();
    $quote = new LineQuote($seed['product'], $seed['snapshot'], Build::AT, Build::DATE, 'USD');

    // The control: the rows really do come back in the reverse of the expected
    // order, so a green run is not insertion-order luck.
    $fetched = PricingSeed::connection()->table('price_lists')->whereIn('id', array_values($seed['lists']))->pluck('code')->map(static fn (mixed $code): string => (string) $code)->all();

    expect($fetched)->toBe(['a', 'B', '9', '10']);

    foreach (['10' => '100.0000', '9' => '90.0000', 'B' => '80.0000', 'a' => '70.0000'] as $winner => $price) {
        $priced = dpeEngine()->quoteLine($quote);

        expect($priced->unitPrice)->toBe($price, "Expected the code [{$winner}] to win.")
            ->and($priced->source->priceListId)->toBe(strtoupper($seed['lists'][(string) $winner]));

        PricingSeed::connection()->table('price_lists')->where('id', $seed['lists'][(string) $winner])->update(['is_active' => false]);
    }

    // Every promotion is out: the product's own price (step 6) is what is left.
    $last = dpeEngine()->quoteLine($quote);

    expect($last->unitPrice)->toBe('1.0000')
        ->and($last->source->step)->toBe(6);
});

it('orders two customer lists on equal priorities bytewise: B before a (step 1 is a separate comparator)', function () {
    $bare = dpeBareProduct('CUST');
    $customer = PricingSeed::customer('Tie');
    $lists = [];

    foreach (['a' => '70.0000', 'B' => '80.0000'] as $code => $price) {
        $list = PricingSeed::priceList((string) $code);

        PricingSeed::item($list, $bare['product'], null, '1.0000', $price);
        PricingSeed::assignment($list, 'customer', $customer);

        $lists[(string) $code] = $list;
    }

    $quote = dpeBareQuote($bare, $customer);

    expect(dpeEngine()->quoteLine($quote)->unitPrice)->toBe('80.0000');

    PricingSeed::connection()->table('price_lists')->where('id', $lists['B'])->update(['is_active' => false]);

    expect(dpeEngine()->quoteLine($quote)->unitPrice)->toBe('70.0000');
});

// ------------------------------------------------- the read path never orders

it('never orders or limits a candidate set in SQL while it quotes', function () {
    $case = dpeFixture('line', 'line-case-of-24-with-discount-exclusive');
    $input = (array) $case['input'];

    FixtureDatabase::seedLine($input);

    $billDiscount = PricingSeed::discount('BILL', ['scope' => 'bill']);

    $db = PricingSeed::connection();
    $db->flushQueryLog();
    $db->enableQueryLog();

    dpeEngine()->quoteLine(dpeQuote((array) $input['ladder'], (string) ((array) $input['product'])['id'], (string) $input['quantity'], (string) $input['document_date'], (string) $input['discount_id']));
    dpeEngine()->computeTax(new TaxQuote((string) ((array) $input['tax_group'])['id'], '100.0000', '1.0000', Build::DATE, TaxContext::Sale));
    dpeEngine()->billDiscount(new BillQuote($billDiscount, Build::AT, ['10.0000']));

    $statements = array_map(static fn (array $entry): string => strtolower((string) $entry['query']), $db->getQueryLog());
    $db->disableQueryLog();

    expect($statements)->not->toBe([]);

    foreach ($statements as $statement) {
        expect($statement)->not->toContain('order by');
    }

    // `limit` is allowed only on a key lookup (what `first()` and `value()` add).
    foreach ($statements as $statement) {
        if (str_contains($statement, 'limit')) {
            expect($statement)->toMatch('/where "(id|product_id)" = \?/');
        }
    }

    // The candidate reads are present and unlimited (anti-vacuity).
    $candidate = array_values(array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'from "price_lists"') || str_contains($statement, 'from "price_list_items"') || str_contains($statement, 'from "tax_group_taxes"') || str_contains($statement, 'from "tax_rates"')));

    expect($candidate)->not->toBe([]);

    foreach ($candidate as $statement) {
        expect($statement)->not->toContain('limit');
    }

    // The SQL predicates over candidate rows are id equality only: nothing
    // about activity, deletion, window or currency is asked of the database.
    $candidateTables = ['price_lists', 'price_list_items', 'price_list_assignments', 'tax_group_taxes', 'taxes', 'tax_rates'];
    $reads = array_values(array_filter($statements, static function (string $statement) use ($candidateTables): bool {
        foreach ($candidateTables as $table) {
            if (str_contains($statement, 'from "'.$table.'"')) {
                return true;
            }
        }

        return false;
    }));

    foreach ($candidateTables as $table) {
        expect(array_filter($reads, static fn (string $statement): bool => str_contains($statement, 'from "'.$table.'"')))->not->toBe([], "No read of [{$table}] was seen.");
    }

    foreach ($reads as $statement) {
        foreach (['is_active', 'deleted_at', 'currency', 'starts_at', 'ends_at'] as $column) {
            expect($statement)->not->toContain($column);
        }
    }
});

// ------------------------------------------------- D-34: a real 0.0000 rate row

it('reads a suspended levy as a 0.0000 rate row and gives the inclusive residue to the VAT before it', function () {
    $authority = PricingSeed::authority('D34');
    $vat = PricingSeed::tax($authority, 'VAT');
    $levy = PricingSeed::tax($authority, 'LEVY');

    PricingSeed::rate($vat, '15.0000', '2020-01-01');
    // The levy was 2 % until 2025-01-01 and is suspended from that day by a 0.0000 row.
    PricingSeed::rate($levy, '2.0000', '2020-01-01', '2025-01-01');
    PricingSeed::rate($levy, '0.0000', '2025-01-01');

    $group = PricingSeed::group('INCL', ['is_inclusive' => true]);

    PricingSeed::member($group, $vat, 1);
    PricingSeed::member($group, $levy, 2);

    $breakdown = dpeEngine()->computeTax(new TaxQuote($group, '9.9900', '1.0000', '2025-06-01', TaxContext::Sale));

    // The amounts of fixture tax-inclusive-residue-skips-a-zero-rate-member (6a), typed by hand.
    expect($breakdown->isInclusive)->toBeTrue()
        ->and($breakdown->net)->toBe('8.6870')
        ->and(array_map(static fn ($line): array => [$line->code, $line->rate, $line->base, $line->amount], $breakdown->lines))->toBe([
            ['VAT', '15.0000', '8.6870', '1.3030'],
            ['LEVY', '0.0000', '8.6870', '0.0000'],
        ])
        ->and($breakdown->total)->toBe('1.3030');

    // Control: before the suspension the levy is a live 2 % member and takes its own share.
    expect(dpeEngine()->computeTax(new TaxQuote($group, '9.9900', '1.0000', '2024-06-01', TaxContext::Sale))->lines[1]->rate)->toBe('2.0000');
});

// ------------------------------------------- W-4 (D-38): a bad applies_to is refused

/**
 * Stored `applies_to` values that are not the D-32 shape (raw text, as the
 * column would hold it). Every one must be refused, none may widen the discount.
 *
 * @return array<string, array{string}>
 */
function dpeMalformedAppliesTo(): array
{
    return [
        'a bare string' => ['"X"'],
        'a bare number' => ['123'],
        'an empty list' => ['[]'],
        'a list of ids' => ['["X"]'],
        'a JSON null' => ['null'],
        'a products value that is a string' => ['{"products": "X"}'],
        'a non-string entry' => ['{"products": [123]}'],
        'a products value that is an object' => ['{"products": {"a": "X"}}'],
        'an unknown key' => ['{"skus": ["X"]}'],
        'an unknown key beside a valid one' => ['{"products": ["X"], "tags": []}'],
        'a nested list entry' => ['{"products": [["X"]]}'],
    ];
}

it('refuses a malformed stored applies_to as invalid_amount, and never widens the discount', function (string $stored) {
    $bare = dpeBareProduct();
    $discount = PricingSeed::discount('BAD', ['applies_to' => $stored]);

    $reason = Build::reasonOf(fn () => dpeEngine()->quoteLine(dpeBareQuote($bare, null, $discount)));

    expect($reason)->toBe('invalid_amount');

    try {
        dpeEngine()->quoteLine(dpeBareQuote($bare, null, $discount));
    } catch (PricingRefused $refused) {
        expect($refused->getMessage())->toContain(strtoupper($discount))->toContain('applies_to');
    }
})->with(dpeMalformedAppliesTo());

it('refuses a malformed stored applies_to on the bill path as well', function () {
    $discount = PricingSeed::discount('BADBILL', ['scope' => 'bill', 'applies_to' => '{"products": "X"}']);

    expect(Build::reasonOf(fn () => dpeEngine()->billDiscount(new BillQuote($discount, Build::AT, ['10.0000']))))->toBe('invalid_amount');
});

it('refuses undecodable applies_to text when the stand-in lets it be stored (defence only on the engines, whose column refuses it)', function () {
    $bare = dpeBareProduct();

    try {
        $discount = PricingSeed::discount('TEXT', ['applies_to' => 'not json']);
    } catch (QueryException) {
        // The column's JSON validity check refused the insert: the branch is defence only.
        expect(true)->toBeTrue();

        return;
    }

    expect(Build::reasonOf(fn () => dpeEngine()->quoteLine(dpeBareQuote($bare, null, $discount))))->toBe('invalid_amount');
});

it('still applies to everything for NULL and {}, applies to a listed product, and skips an unlisted one (controls)', function () {
    $bare = dpeBareProduct();

    $everything = PricingSeed::discount('NULLALL', ['applies_to' => null]);
    $emptyObject = PricingSeed::discount('EMPTYALL', ['applies_to' => '{}']);
    $listed = PricingSeed::discount('LISTED', ['applies_to' => json_encode(['products' => [strtolower($bare['product'])]])]);
    $other = PricingSeed::discount('OTHER', ['applies_to' => json_encode(['products' => [strtolower(PricingSeed::product('ELSE'))]])]);

    foreach ([$everything, $emptyObject, $listed] as $applies) {
        expect(dpeEngine()->quoteLine(dpeBareQuote($bare, null, $applies))->discountAmount)->toBe('0.1000');
    }

    expect(Build::reasonOf(fn () => dpeEngine()->quoteLine(dpeBareQuote($bare, null, $other))))->toBe('not_applicable');
});

// -------------------------------------------- S-7: a dangling tax link is named

it('names the tax when a group links a tax that does not exist, and keeps the unknown_tax_group reason', function () {
    $group = PricingSeed::group('DANGLING');
    $ghost = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $db = PricingSeed::connection();

    $db->statement('PRAGMA foreign_keys = OFF');

    try {
        $db->table('tax_group_taxes')->insert(['tax_group_id' => $group, 'tax_id' => $ghost, 'sequence' => 1]);
    } finally {
        $db->statement('PRAGMA foreign_keys = ON');
    }

    $quote = new TaxQuote($group, '100.0000', '1.0000', Build::DATE, TaxContext::Sale);

    try {
        dpeEngine()->computeTax($quote);
    } catch (PricingRefused $refused) {
        expect($refused->reason->value)->toBe('unknown_tax_group')
            ->and($refused->getMessage())->toContain($ghost)->toContain($group);

        return;
    }

    throw new \RuntimeException('Expected a refusal.');
});

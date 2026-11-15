<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\AddSaleLine;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Pricing\PricingEngine;
use Flexipos\Contracts\Pricing\TaxContext;
use Flexipos\Contracts\Pricing\TaxQuote;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLine;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineHandler;
use Flexipos\Sales\Features\AddSaleLine\AddSaleLineResult;
use Flexipos\Sales\Features\UpdateSaleLine\UpdateSaleLine;
use Flexipos\Sales\Features\UpdateSaleLine\UpdateSaleLineHandler;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Tests\Support\FakePricingEngine;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Flexipos\Sales\Tests\Support\SalesWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 15 - price override and the discount approval
|     (AC-7, AC-23a, AC-24A, AC-55; D-37, D-40, D-47, D-59, D-72; R-8, R-12, R-38)
|--------------------------------------------------------------------------
|
| Approvals are seeded decided (a raw row, as the decide step would leave it):
| this file is about what a line does with one, not how one is decided.
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
function slaWorld(bool $inclusive = false): array
{
    $till = SalesWorld::till();
    $group = SalesWorld::taxGroup('TX10', '10.0000', $inclusive);
    $catalogue = SalesWorld::product('P1', '100.0000', $group, ['allow_price_override' => true]);
    $case = PricingSeed::unit('CASE');
    SalesWorld::conversion($case, $catalogue['unit'], '24.000000');

    return [
        'till' => $till,
        'sale' => SalesWorld::sale($till),
        'product' => $catalogue['product'],
        'unit' => $catalogue['unit'],
        'case' => $case,
        'group' => $group,
        'manager' => SalesSeed::employee('manager'),
    ];
}

/**
 * A decided approval for the sale, as the decide step leaves one.
 *
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>|null  $payload
 * @param  array<string, mixed>  $overrides
 */
function slaApproval(array $w, string $type, ?array $payload, array $overrides = []): string
{
    return SalesSeed::approval($w['till']['branch'], $w['till']['cashier'], array_merge([
        'type' => $type,
        'reference_type' => 'sale',
        'reference_id' => $w['sale'],
        'payload' => $payload === null ? null : json_encode($payload),
        'status' => 'approved',
        'decided_by' => $w['manager'],
        'decided_at' => SalesSeed::NOW,
        'decision_method' => 'pin_local',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $w
 * @return array{product_id: string, unit_id: string, unit_price: string}
 */
function slaOverride(array $w, string $price, ?string $unit = null): array
{
    return ['product_id' => $w['product'], 'unit_id' => $unit ?? $w['unit'], 'unit_price' => $price];
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $fields
 */
function slaAdd(array $w, array $fields = []): AddSaleLineResult
{
    /** @var AddSaleLineHandler $handler */
    $handler = app(AddSaleLineHandler::class);

    return $handler->handle(new AddSaleLine(array_merge(['sale_id' => $w['sale'], 'product_id' => $w['product'], 'unit_id' => $w['unit'], 'quantity' => '2'], $fields), $w['till']['cashier']));
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $fields
 */
function slaUpdate(array $w, string $lineId, array $fields): void
{
    /** @var UpdateSaleLineHandler $handler */
    $handler = app(UpdateSaleLineHandler::class);

    $handler->handle(new UpdateSaleLine(array_merge(['sale_id' => $w['sale'], 'line_id' => $lineId], $fields), $w['till']['cashier']));
}

/**
 * @return array<string, mixed>
 */
function slaState(): array
{
    $c = SalesSeed::connection();

    return [
        'lines' => $c->table('sale_items')->get()->map(fn ($r) => (array) $r)->all(),
        'approvals' => $c->table('approval_requests')->get()->map(fn ($r) => (array) $r)->all(),
        'sales' => $c->table('sales')->get()->map(fn ($r) => (array) $r)->all(),
        'audit' => SalesSeed::audit(),
    ];
}

it('refuses an override with no approval as approval_required, and writes nothing (AC-7)', function () {
    $w = slaWorld();
    $before = slaState();

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slaAdd($w, ['unit_price' => '7.5']));

    expect(slaState())->toBe($before);
});

it('stores the override with its subtotal and tax from computeTax, no discount, and the approver as price_overridden_by (AC-7, R-12)', function () {
    $w = slaWorld();
    $approval = slaApproval($w, 'price_override', slaOverride($w, '7.5000'));

    $result = slaAdd($w, ['unit_price' => '7.5', 'approval_id' => strtolower($approval)]);

    // 7.5000 x 2 = 15.0000, 10 percent exclusive: 1.5000 tax, 16.5000 total.
    expect(SalesSeed::row('sale_items', $result->lineId))->toMatchArray([
        'list_price' => '100.0000',
        'unit_price' => '7.5000',
        'subtotal' => '15.0000',
        'discount_id' => null,
        'discount_amount' => '0.0000',
        'tax_amount' => '1.5000',
        'line_total' => '16.5000',
        'price_overridden_by' => $w['manager'],
    ]);

    expect(SalesSeed::row('approval_requests', $approval)['consumed_at'])->not->toBeNull()
        ->and(SalesSeed::row('sales', $w['sale']))->toMatchArray(['subtotal' => '15.0000', 'tax_total' => '1.5000', 'total' => '16.5000']);
});

it('takes the line total as tax.net + tax.total for an inclusive group too, without branching on inclusivity (AC-7)', function () {
    $w = slaWorld(true);
    $approval = slaApproval($w, 'price_override', slaOverride($w, '11.0000'));

    $result = slaAdd($w, ['unit_price' => '11', 'approval_id' => $approval]);

    // 11.0000 x 2 = 22.0000 gross, inclusive 10 percent: net 20.0000 and tax 2.0000, so the total is the gross.
    expect(SalesSeed::row('sale_items', $result->lineId))->toMatchArray(['subtotal' => '22.0000', 'tax_amount' => '2.0000', 'line_total' => '22.0000']);
});

it('feeds a fixed tax the base quantity, not the line quantity: a case of 24 charges the fixed tax 24 times (R-12)', function () {
    $w = slaWorld();

    // A second tax on the group: a fixed 2.0000 per base unit.
    $fixed = PricingSeed::tax(PricingSeed::authority('AF'), 'FIXED2', ['type' => 'fixed']);
    PricingSeed::rate($fixed, '2.0000', '2020-01-01');
    PricingSeed::member($w['group'], $fixed, 2);

    $approval = slaApproval($w, 'price_override', slaOverride($w, '10.0000', $w['case']));

    $result = slaAdd($w, ['unit_id' => $w['case'], 'quantity' => '1', 'unit_price' => '10', 'approval_id' => $approval]);

    $engine = app(PricingEngine::class);
    $asBase = $engine->computeTax(new TaxQuote($w['group'], '10.0000', '24.0000', '2026-11-12', TaxContext::Sale));
    $asLine = $engine->computeTax(new TaxQuote($w['group'], '10.0000', '1.0000', '2026-11-12', TaxContext::Sale));

    $row = SalesSeed::row('sale_items', $result->lineId);

    expect($asBase->total)->not->toBe($asLine->total)
        ->and($row['tax_amount'])->toEqual($asBase->total)
        ->and($row['line_total'])->toEqual(Money::add($asBase->net, $asBase->total))
        ->and($row['base_quantity'])->toEqual('24.0000');
});

it('refuses an override whose subtotal does not fit DECIMAL(18,4) as out_of_range (R-39)', function () {
    $w = slaWorld();
    $approval = slaApproval($w, 'price_override', slaOverride($w, '99999999999999.9999'));

    SalesRefusals::expect(SalesRefusal::OutOfRange, fn () => slaAdd($w, ['unit_price' => '99999999999999.9999', 'quantity' => '2', 'approval_id' => $approval]));

    expect(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
});

it('refuses an override price that is negative, has a fifth decimal or too many digits as validation', function (mixed $price) {
    $w = slaWorld();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => slaAdd($w, ['unit_price' => $price]));
})->with(['negative' => ['-1'], 'five decimals' => ['1.00001'], 'fifteen digits' => ['100000000000000'], 'a float' => [1.5], 'text' => ['abc']]);

it('matches the payload by value: a stored "7.5" and an upper-case id match a write of 7.5000 (R-8, R-38)', function () {
    $w = slaWorld();
    $approval = slaApproval($w, 'price_override', ['product_id' => strtolower($w['product']), 'unit_id' => strtolower($w['unit']), 'unit_price' => '7.5']);

    $result = slaAdd($w, ['unit_price' => '7.5000', 'approval_id' => $approval]);

    expect(SalesSeed::row('sales', $w['sale']))->toMatchArray(['total' => '16.5000'])
        ->and($result->lineNo)->toBe(1);
});

it('refuses a payload that differs from the write as approval_mismatch, leaving the approval unused (F-16)', function (string $field, string $value) {
    $w = slaWorld();
    $other = SalesWorld::product('P9', '5.0000', $w['group'], ['allow_price_override' => true]);
    $payload = slaOverride($w, '7.5000');

    $payload[$field] = match ($field) {
        'product_id' => $other['product'],
        'unit_id' => $w['case'],
        default => $value,
    };

    $approval = slaApproval($w, 'price_override', $payload);

    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $approval]));

    expect(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull()
        ->and(SalesSeed::connection()->table('sale_items')->count())->toBe(0);
})->with(['price' => ['unit_price', '7.6000'], 'product' => ['product_id', ''], 'unit' => ['unit_id', '']]);

it('uses an approval once: reusing it is approval_already_used (AC-24)', function () {
    $w = slaWorld();
    $approval = slaApproval($w, 'price_override', slaOverride($w, '7.5000'));

    slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $approval]);

    // The second line is a new line: the first one's consumed approval does not cover it, and the id is spent.
    SalesRefusals::expect(SalesRefusal::ApprovalAlreadyUsed, fn () => slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $approval]));

    expect(SalesSeed::connection()->table('sale_items')->count())->toBe(1);
});

it('refuses an approval that is not approved, or is for another type, sale or branch', function (array $overrides, SalesRefusal $expected) {
    $w = slaWorld();
    $otherBranch = SalesSeed::branch();
    $otherSale = SalesWorld::sale($w['till']);

    $overrides = array_map(static fn (mixed $value): mixed => match ($value) {
        '@other_branch' => $otherBranch,
        '@other_sale' => $otherSale,
        default => $value,
    }, $overrides);

    $approval = slaApproval($w, 'price_override', slaOverride($w, '7.5000'), $overrides);

    SalesRefusals::expect($expected, fn () => slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $approval]));

    expect(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
})->with([
    'pending' => [['status' => 'pending', 'decided_by' => null, 'decided_at' => null], SalesRefusal::ApprovalNotApproved],
    'rejected' => [['status' => 'rejected'], SalesRefusal::ApprovalNotApproved],
    'a void approval' => [['type' => 'void', 'payload' => null], SalesRefusal::ApprovalMismatch],
    'another sale' => [['reference_id' => '@other_sale'], SalesRefusal::ApprovalMismatch],
    'a goodwill reference' => [['reference_type' => 'goodwill_refund', 'reference_id' => null], SalesRefusal::ApprovalMismatch],
]);

it('refuses an approval seeded at another branch as approval_mismatch and leaves it unconsumed (AC-24A, D-72: a defence-in-depth row)', function () {
    $w = slaWorld();
    $elsewhere = SalesSeed::branch();

    // RequestApproval would refuse a sale-bound request at another branch; a raw row is the only way to reach the gate with one.
    $foreign = slaApproval($w, 'price_override', slaOverride($w, '7.5000'), ['branch_id' => $elsewhere]);

    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $foreign]));

    expect(SalesSeed::row('approval_requests', $foreign)['consumed_at'])->toBeNull()
        ->and(SalesSeed::connection()->table('sale_items')->count())->toBe(0);

    // The same approval at the sale's own branch is consumed.
    $own = slaApproval($w, 'price_override', slaOverride($w, '7.5000'));

    slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $own]);

    expect(SalesSeed::row('approval_requests', $own)['consumed_at'])->not->toBeNull();
});

it('refuses an override on a product that does not allow one (F-16)', function () {
    $w = slaWorld();
    $strict = SalesWorld::product('P8', '5.0000', $w['group']);
    $approval = slaApproval($w, 'price_override', ['product_id' => $strict['product'], 'unit_id' => $strict['unit'], 'unit_price' => '1.0000']);

    SalesRefusals::expect(SalesRefusal::PriceOverrideNotAllowed, fn () => slaAdd($w, ['product_id' => $strict['product'], 'unit_id' => $strict['unit'], 'unit_price' => '1', 'approval_id' => $approval]));

    expect(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
});

it('refuses an override together with an item discount as discount_with_override (F-16)', function () {
    $w = slaWorld();
    $discount = PricingSeed::discount('D10');
    $approval = slaApproval($w, 'price_override', slaOverride($w, '7.5000'));

    SalesRefusals::expect(SalesRefusal::DiscountWithOverride, fn () => slaAdd($w, ['unit_price' => '7.5', 'discount_id' => $discount, 'approval_id' => $approval]));

    expect(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
});

it('needs an approval for a discount that requires one, and accepts it with a matching consumed one (AC-23a, F-12)', function () {
    $w = slaWorld();
    $gated = PricingSeed::discount('GATED', ['requires_approval' => true]);
    $free = PricingSeed::discount('FREE10');

    // A discount without the flag needs none.
    expect(slaAdd($w, ['discount_id' => $free])->lineTotal)->toBe('198.0000');

    $before = slaState();

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slaAdd($w, ['discount_id' => $gated]));

    expect(slaState())->toBe($before);

    $approval = slaApproval($w, 'discount', ['discount_id' => strtolower($gated)]);

    $result = slaAdd($w, ['discount_id' => $gated, 'approval_id' => $approval]);

    // 200.0000 less 10 percent is 180.0000, plus 10 percent tax is 198.0000.
    expect($result->lineTotal)->toBe('198.0000')
        ->and(SalesSeed::row('sale_items', $result->lineId)['discount_id'])->toBe($gated)
        ->and(SalesSeed::row('approval_requests', $approval)['consumed_at'])->not->toBeNull();
});

it('refuses a discount approval for another discount as approval_mismatch (AC-23a)', function () {
    $w = slaWorld();
    $gated = PricingSeed::discount('GATED', ['requires_approval' => true]);
    $other = PricingSeed::discount('OTHER');
    $approval = slaApproval($w, 'discount', ['discount_id' => $other]);

    SalesRefusals::expect(SalesRefusal::ApprovalMismatch, fn () => slaAdd($w, ['discount_id' => $gated, 'approval_id' => $approval]));

    expect(SalesSeed::row('approval_requests', $approval)['consumed_at'])->toBeNull();
});

it('lets a re-price keep an approval this sale already consumed: a new quantity on an overridden line needs none (AC-55, D-59)', function () {
    $w = slaWorld();
    $approval = slaApproval($w, 'price_override', slaOverride($w, '7.5000'));

    $line = slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $approval]);

    slaUpdate($w, $line->lineId, ['quantity' => '4']);

    expect(SalesSeed::row('sale_items', $line->lineId))->toMatchArray([
        'unit_price' => '7.5000',
        'subtotal' => '30.0000',
        'line_total' => '33.0000',
        'price_overridden_by' => $w['manager'],
    ])
        // No second approval was consumed: still the one row.
        ->and(SalesSeed::connection()->table('approval_requests')->whereNotNull('consumed_at')->count())->toBe(1);
});

it('needs a new approval for a different override price or a different unit on a re-price (AC-55)', function () {
    $w = slaWorld();
    $approval = slaApproval($w, 'price_override', slaOverride($w, '7.5000'));
    $line = slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $approval]);

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slaUpdate($w, $line->lineId, ['unit_price' => '8']));
    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slaUpdate($w, $line->lineId, ['unit_id' => $w['case']]));

    $second = slaApproval($w, 'price_override', slaOverride($w, '8.0000'));

    slaUpdate($w, $line->lineId, ['unit_price' => '8', 'approval_id' => $second]);

    expect(SalesSeed::row('sale_items', $line->lineId)['unit_price'])->toEqual('8.0000');
});

it('lets a re-price keep a gated discount this sale already consumed, and refuses a different gated discount (AC-55)', function () {
    $w = slaWorld();
    $gated = PricingSeed::discount('GATED', ['requires_approval' => true]);
    $gatedToo = PricingSeed::discount('GATED2', ['requires_approval' => true]);
    $approval = slaApproval($w, 'discount', ['discount_id' => $gated]);

    $line = slaAdd($w, ['discount_id' => $gated, 'approval_id' => $approval]);

    slaUpdate($w, $line->lineId, ['quantity' => '4']);

    expect(SalesSeed::row('sale_items', $line->lineId)['discount_id'])->toBe($gated);

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slaUpdate($w, $line->lineId, ['discount_id' => $gatedToo]));
});

it('does not let another sale\'s consumed approval cover this sale\'s re-price', function () {
    $w = slaWorld();
    $otherSale = SalesWorld::sale($w['till']);
    $approval = slaApproval($w, 'price_override', slaOverride($w, '7.5000'), ['reference_id' => $otherSale, 'consumed_at' => SalesSeed::NOW]);

    // The other sale's approval is spent, and names the other sale: this sale has nothing to lean on.
    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slaAdd($w, ['unit_price' => '7.5']));

    expect(SalesSeed::row('approval_requests', $approval)['consumed_at'])->not->toBeNull();
});

it('drops an override on a re-price when the caller says unit_price is null, pricing it from the engine again', function () {
    $w = slaWorld();
    $approval = slaApproval($w, 'price_override', slaOverride($w, '7.5000'));
    $line = slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $approval]);

    slaUpdate($w, $line->lineId, ['unit_price' => null]);

    expect(SalesSeed::row('sale_items', $line->lineId))->toMatchArray(['unit_price' => '100.0000', 'price_overridden_by' => null, 'line_total' => '220.0000']);
});

it('quotes the override line once, for its list price and tax group only', function () {
    $w = slaWorld();
    $approval = slaApproval($w, 'price_override', slaOverride($w, '7.5000'));

    $fake = FakePricingEngine::install();

    slaAdd($w, ['unit_price' => '7.5', 'approval_id' => $approval]);

    expect($fake->quotes)->toHaveCount(1)
        ->and($fake->quotes[0]->discountId)->toBeNull()
        ->and($fake->taxQuotes)->toHaveCount(1)
        ->and($fake->taxQuotes[0]->amount)->toBe('15.0000')
        ->and($fake->taxQuotes[0]->baseQuantity)->toBe('2.0000')
        ->and($fake->taxQuotes[0]->context)->toBe(TaxContext::Sale)
        ->and($fake->taxQuotes[0]->documentDate)->toBe('2026-11-12');
});

it('has a real resolver behind it: the override uses the snapshot quantity, rounded to the unit precision', function () {
    $w = slaWorld();
    $approval = slaApproval($w, 'price_override', slaOverride($w, '1.0000'));

    $snapshot = app(UnitResolver::class)->snapshot($w['product'], $w['unit'], '2.5000');
    $result = slaAdd($w, ['unit_price' => '1', 'quantity' => '2.5', 'approval_id' => $approval]);

    expect($snapshot->quantity)->toBe('2.5000')
        ->and(SalesSeed::row('sale_items', $result->lineId)['subtotal'])->toEqual('2.5000');
});

it('uses an unknown approval id as approval_required', function () {
    $w = slaWorld();

    SalesRefusals::expect(SalesRefusal::ApprovalRequired, fn () => slaAdd($w, ['unit_price' => '7.5', 'approval_id' => (string) Str::ulid()]));
});

<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\AddPurchaseOrderLine;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Pricing\PricingRefusal;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\AddPurchaseOrderLine\AddPurchaseOrderLine;
use Flexipos\Purchasing\Features\AddPurchaseOrderLine\AddPurchaseOrderLineHandler;
use Flexipos\Purchasing\Features\AddPurchaseOrderLine\AddPurchaseOrderLineResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 16 - AddPurchaseOrderLine (AC-42 to AC-47, AC-51, AC-53)
|--------------------------------------------------------------------------
|
| The real UnitResolver and the real PurchaseLinePricing: the line is the
| resolver's quad plus the engine's amounts, stored and returned as four-decimal
| strings, summed into the header by the rollup, and never computed here.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/purchasing-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    puEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $fields
 */
function puAddOrderLine(array $fields, ?string $actorId): AddPurchaseOrderLineResult
{
    /** @var AddPurchaseOrderLineHandler $handler */
    $handler = app(AddPurchaseOrderLineHandler::class);

    return $handler->handle(new AddPurchaseOrderLine($fields, $actorId));
}

/**
 * A draft order on the desk.
 *
 * @param  array<string, string>  $desk
 * @param  array<string, mixed>  $overrides
 */
function puAddLineOrder(array $desk, array $overrides = []): string
{
    return PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], array_merge(['created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']], $overrides));
}

/**
 * The fields of a line of the worked example: 12 each at 3.3333, 10% off, under
 * the group given (a 10% exclusive percentage group makes the figures AC-43 states).
 *
 * @param  array<string, string>  $desk
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function puOrderLineFields(array $desk, string $orderId, string $group, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(),
        'order_id' => $orderId,
        'product_id' => $desk['product'],
        'unit_id' => $desk['unit'],
        'quantity' => '12',
        'unit_cost' => '3.3333',
        'discount_percent' => '10.00',
        'tax_group_id' => $group,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $fields
 */
function puAddOrderLineRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puAddOrderLine($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-43: stores the worked example exactly, as four-decimal strings, with the resolver s quad and the engine s amounts, one audit row against the order', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDEX');
    $order = puAddLineOrder($desk);
    $fields = puOrderLineFields($desk, $order, $group, ['expected_date' => '2026-12-01']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puAddOrderLine(array_merge($fields, ['id' => strtolower($fields['id']), 'order_id' => strtolower($order)]), strtolower($desk['buyer']));

    $snapshot = app(UnitResolver::class)->snapshot($desk['product'], $desk['unit'], '12');

    expect($result->replayed)->toBeFalse()
        ->and($result->line)->toBe([
            'id' => $fields['id'],
            'order_id' => $order,
            'line_no' => 1,
            'product_id' => $desk['product'],
            'description' => 'Product P-D1',
            'unit_id' => $snapshot->unitId,
            'quantity' => $snapshot->quantity,
            'unit_factor' => $snapshot->unitFactor,
            'base_quantity' => $snapshot->baseQuantity,
            'unit_cost' => '3.3333',
            'subtotal' => '39.9996',
            'discount_percent' => '10.0000',
            'discount_amount' => '4.0000',
            'tax_group_id' => $group,
            'tax_amount' => '3.6000',
            'line_total' => '39.5996',
            'received_quantity' => '0.0000',
            'received_base_quantity' => '0.0000',
            'expected_date' => '2026-12-01',
        ])
        ->and($result->order)->toMatchArray([
            'subtotal' => '39.9996',
            'discount_total' => '4.0000',
            'tax_total' => '3.6000',
            'total' => '39.5996',
            'updated_at' => '2026-11-17 10:00:00.000',
            'updated_by' => $desk['buyer'],
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $desk['buyer'],
        'branch_id' => $desk['branch'],
        'module' => PurchasingAuditActions::MODULE,
        'action' => PurchasingAuditActions::ORDER_LINE_ADDED,
        'subject_type' => 'purchase_order',
        'subject_id' => $order,
        'before' => null,
    ])
        ->and($audit['after']['line_total'])->toBe('39.5996');
});

it('AC-43: the inclusive twin stores tax 3.2727 and a line total of 35.9996', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDIN', '10.0000', true);
    $order = puAddLineOrder($desk);

    $result = puAddOrderLine(puOrderLineFields($desk, $order, $group), $desk['buyer']);

    expect($result->line)->toMatchArray(['tax_amount' => '3.2727', 'line_total' => '35.9996', 'subtotal' => '39.9996', 'discount_amount' => '4.0000'])
        ->and($result->order['total'])->toBe('35.9996');
});

it('AC-45: the header is the sum of the stored lines plus shipping, 39.5996 and 10.0000 and 2.5000 make 52.0996, and a second line moves nothing already stored', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDSUM');
    $zero = PurchasingSeed::taxGroup('ADDZERO', '0.0000');
    $order = puAddLineOrder($desk, ['shipping' => '2.5000', 'total' => '2.5000']);

    $first = puAddOrderLine(puOrderLineFields($desk, $order, $group), $desk['buyer']);
    $second = puAddOrderLine(puOrderLineFields($desk, $order, $zero, ['quantity' => '1', 'unit_cost' => '10', 'discount_percent' => '0']), $desk['buyer']);

    expect($second->line)->toMatchArray(['line_no' => 2, 'line_total' => '10.0000', 'tax_amount' => '0.0000'])
        ->and($second->order)->toMatchArray([
            'subtotal' => '49.9996',
            'discount_total' => '4.0000',
            'tax_total' => '3.6000',
            'shipping' => '2.5000',
            'total' => '52.0996',
        ])
        ->and(PurchasingSeed::row('purchase_order_items', $first->lineId)['line_total'])->toEqual(39.5996);
});

it('AC-42: a larger unit is frozen as its quantity, factor and base quantity, and the line is priced in the line unit', function () {
    $desk = PurchasingWorld::desk();
    $units = PurchasingSeed::unitPair('PUEA', 'PUDZ', '12');
    $product = PurchasingSeed::product('DZ-1', $units['base']);
    $group = PurchasingSeed::taxGroup('ADDDZ');
    $order = puAddLineOrder($desk);

    $result = puAddOrderLine(puOrderLineFields($desk, $order, $group, ['product_id' => $product, 'unit_id' => $units['large'], 'quantity' => '2', 'unit_cost' => '10', 'discount_percent' => '0']), $desk['buyer']);

    expect($result->line)->toMatchArray([
        'unit_id' => $units['large'],
        'quantity' => '2.0000',
        'unit_factor' => '12.000000',
        'base_quantity' => '24.0000',
        'subtotal' => '20.0000',
        'tax_amount' => '2.0000',
        'line_total' => '22.0000',
    ]);
});

it('AC-42: the description defaults to the product s name and is stored, a given one is trimmed, and a zero cost is accepted', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDDS');
    $order = puAddLineOrder($desk);

    $defaulted = puAddOrderLine(puOrderLineFields($desk, $order, $group, ['unit_cost' => '0']), $desk['buyer']);
    $named = puAddOrderLine(puOrderLineFields($desk, $order, $group, ['description' => '  Special pack  ']), $desk['buyer']);

    PurchasingSeed::connection()->table('products')->where('id', $desk['product'])->update(['name' => 'Renamed later']);

    expect($defaulted->line)->toMatchArray(['description' => 'Product P-D1', 'unit_cost' => '0.0000', 'subtotal' => '0.0000', 'line_total' => '0.0000'])
        ->and($named->line['description'])->toBe('Special pack')
        ->and(PurchasingSeed::row('purchase_order_items', $defaulted->lineId)['description'])->toBe('Product P-D1');
});

it('AC-44: the group is the explicit one, else the product s purchase group, else the shop default, and it is stored on the line', function () {
    $desk = PurchasingWorld::desk();
    $explicit = PurchasingSeed::taxGroup('ADDX1');
    $productGroup = PurchasingSeed::taxGroup('ADDP1');
    $default = (string) PurchasingSeed::connection()->table('tax_groups')->where('is_default', true)->value('id');
    $withGroup = PurchasingSeed::product('PG-1', $desk['unit'], ['purchase_tax_group_id' => $productGroup]);
    $order = puAddLineOrder($desk);

    $fromExplicit = puAddOrderLine(puOrderLineFields($desk, $order, $explicit, ['product_id' => $withGroup]), $desk['buyer']);
    $fromProduct = puAddOrderLine(puOrderLineFields($desk, $order, $explicit, ['product_id' => $withGroup, 'tax_group_id' => null]), $desk['buyer']);
    $fromDefault = puAddOrderLine(puOrderLineFields($desk, $order, $explicit, ['tax_group_id' => null]), $desk['buyer']);

    expect([$fromExplicit->line['tax_group_id'], $fromProduct->line['tax_group_id'], $fromDefault->line['tax_group_id']])->toBe([$explicit, $productGroup, $default]);
});

it('AC-44 and D-42: no default group is no_default_tax_group, an unknown group is unknown_tax_group, an inactive one is inactive_tax_group, and a sale-only group taxes zero', function () {
    $desk = PurchasingWorld::desk();
    $order = puAddLineOrder($desk);

    PurchasingSeed::connection()->table('tax_groups')->update(['is_default' => false]);

    puAddOrderLineRefused(PurchasingRefusal::NoDefaultTaxGroup, puOrderLineFields($desk, $order, 'x', ['tax_group_id' => null]), $desk['buyer'], 'no default');
    puAddOrderLineRefused(PurchasingRefusal::UnknownTaxGroup, puOrderLineFields($desk, $order, (string) Str::ulid()), $desk['buyer'], 'unknown');
    puAddOrderLineRefused(PurchasingRefusal::InactiveTaxGroup, puOrderLineFields($desk, $order, PurchasingSeed::taxGroup('ADDOFF', '10.0000', false, 'both', ['is_active' => false])), $desk['buyer'], 'inactive');

    $saleOnly = PurchasingSeed::taxGroup('ADDSALE', '10.0000', false, 'sale');

    expect(puAddOrderLine(puOrderLineFields($desk, $order, $saleOnly), $desk['buyer'])->line)->toMatchArray(['tax_amount' => '0.0000', 'line_total' => '35.9996']);
});

it('AC-44: the line is priced at the order s own date, so a rate that began after it does not apply', function () {
    $desk = PurchasingWorld::desk();
    $tax = PricingSeed::tax(PricingSeed::authority('ADDD'), 'ADDDVAT');
    PricingSeed::rate($tax, '10.0000', '2020-01-01', '2026-12-31');
    PricingSeed::rate($tax, '20.0000', '2027-01-01');
    $group = PricingSeed::group('ADDDG');
    PricingSeed::member($group, $tax, 1);

    $early = puAddLineOrder($desk, ['order_date' => '2026-11-16']);
    $late = puAddLineOrder($desk, ['order_date' => '2027-02-01']);

    $fields = ['quantity' => '10', 'unit_cost' => '10', 'discount_percent' => '0'];

    expect(puAddOrderLine(puOrderLineFields($desk, $early, $group, $fields), $desk['buyer'])->line['tax_amount'])->toBe('10.0000')
        ->and(puAddOrderLine(puOrderLineFields($desk, $late, $group, $fields), $desk['buyer'])->line['tax_amount'])->toBe('20.0000');
});

it('AC-46 and AC-91: a float, a negative, text or a missing value in a quantity, cost or percent is validation, and nothing is written', function (array $overrides) {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDV');
    $order = puAddLineOrder($desk);
    $fields = array_merge(puOrderLineFields($desk, $order, $group), $overrides);

    foreach ($overrides as $key => $value) {
        if ($value === '__unset__') {
            unset($fields[$key]);
        }
    }

    puAddOrderLineRefused(PurchasingRefusal::Validation, $fields, $desk['buyer']);
})->with([
    'a float quantity' => [['quantity' => 2.5]],
    'a zero quantity' => [['quantity' => '0']],
    'a negative quantity' => [['quantity' => '-1']],
    'a text quantity' => [['quantity' => 'two']],
    'a quantity of five decimals' => [['quantity' => '1.12345']],
    'no quantity' => [['quantity' => '__unset__']],
    'a float cost' => [['unit_cost' => 3.5]],
    'a negative cost' => [['unit_cost' => '-0.01']],
    'no cost' => [['unit_cost' => '__unset__']],
    'a null cost' => [['unit_cost' => null]],
    'a float percent' => [['discount_percent' => 10.5]],
    'a percent of 100.01' => [['discount_percent' => '100.01']],
    'a percent of three decimals' => [['discount_percent' => '10.123']],
    'a negative percent' => [['discount_percent' => '-1']],
    'a text percent' => [['discount_percent' => 'ten']],
    'a bad id' => [['id' => 'nope']],
    'no id' => [['id' => '__unset__']],
    'a bad product' => [['product_id' => 'nope']],
    'a bad unit' => [['unit_id' => 'nope']],
    'a bad group' => [['tax_group_id' => 'nope']],
    'a description of 256 characters' => [['description' => str_repeat('d', 256)]],
    'a description that is an array' => [['description' => ['x']]],
    'a date with a time' => [['expected_date' => '2026-12-01 10:00:00']],
    'an expected date before the order date' => [['expected_date' => '2026-11-15']],
    'a field that is not accepted' => [['line_total' => '1.0000']],
    'a line number' => [['line_no' => 3]],
]);

it('AC-42: a percent of 0, 100 and 100.00 is accepted, and 100 percent leaves a line total of zero', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDPC');
    $order = puAddLineOrder($desk);

    $full = puAddOrderLine(puOrderLineFields($desk, $order, $group, ['discount_percent' => '100.00']), $desk['buyer']);
    $int = puAddOrderLine(puOrderLineFields($desk, $order, $group, ['discount_percent' => 100]), $desk['buyer']);
    $none = puAddOrderLine(puOrderLineFields($desk, $order, $group, ['discount_percent' => '0']), $desk['buyer']);

    expect($full->line)->toMatchArray(['discount_percent' => '100.0000', 'discount_amount' => '39.9996', 'line_total' => '0.0000'])
        ->and($int->line['line_total'])->toBe('0.0000')
        ->and($none->line['discount_amount'])->toBe('0.0000');
});

it('AC-47: a quantity of 15 integer digits is out_of_range, an amount the engine cannot fit is out_of_range, and nothing is written', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDOR');
    $order = puAddLineOrder($desk);

    puAddOrderLineRefused(PurchasingRefusal::OutOfRange, puOrderLineFields($desk, $order, $group, ['quantity' => '123456789012345']), $desk['buyer'], 'quantity');
    puAddOrderLineRefused(PurchasingRefusal::OutOfRange, puOrderLineFields($desk, $order, $group, ['unit_cost' => '123456789012345']), $desk['buyer'], 'cost');

    $refused = PurchasingRefusals::capture(fn () => puAddOrderLine(puOrderLineFields($desk, $order, $group, ['quantity' => '99999999999999', 'unit_cost' => '99999999999999']), $desk['buyer']));

    expect($refused->code())->toBe('out_of_range')
        ->and($refused->cause)->toBe(PricingRefusal::OutOfRange);
});

it('AC-47: a header total that cannot fit refuses the line that caused it, and the line, the totals and the audit trail are untouched', function () {
    $desk = PurchasingWorld::desk();
    $zero = PurchasingSeed::taxGroup('ADDHT', '0.0000');
    $order = puAddLineOrder($desk);
    PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $zero, 1, ['subtotal' => '99999999999999.0000', 'line_total' => '99999999999999.0000', 'tax_amount' => '0.0000']);

    puAddOrderLineRefused(PurchasingRefusal::OutOfRange, puOrderLineFields($desk, $order, $zero, ['quantity' => '1', 'unit_cost' => '5', 'discount_percent' => '0']), $desk['buyer']);

    expect(PurchasingSeed::connection()->table('purchase_order_items')->where('order_id', $order)->count())->toBe(1);
});

it('AC-42 and AC-23: an unknown unit and a unit of another dimension are unit_unresolvable, carrying the resolver s reason', function (string $case, UnitRefusal $cause) {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDU');
    $order = puAddLineOrder($desk);

    $unit = $case === 'unknown unit' ? (string) Str::ulid() : PricingSeed::unit('PUKG', 'mass');

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::UnitUnresolvable, fn () => puAddOrderLine(puOrderLineFields($desk, $order, $group, ['unit_id' => $unit]), $desk['buyer']));

    expect($refused->cause)->toBe($cause)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'unknown unit' => ['unknown unit', UnitRefusal::UnknownUnit],
    'another dimension' => ['another dimension', UnitRefusal::CrossDimension],
]);

it('AC-42 and D-39: an unknown, inactive or soft-deleted product is unknown_product, and one that cannot be bought is product_not_purchasable', function (array $overrides, PurchasingRefusal $reason) {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDPR');
    $order = puAddLineOrder($desk);
    $product = PurchasingSeed::product('REF-'.substr((string) Str::ulid(), -8), $desk['unit'], $overrides);

    puAddOrderLineRefused($reason, puOrderLineFields($desk, $order, $group, ['product_id' => $product]), $desk['buyer']);
})->with([
    'inactive' => [['is_active' => false], PurchasingRefusal::UnknownProduct],
    'soft-deleted' => [['deleted_at' => PurchasingSeed::NOW], PurchasingRefusal::UnknownProduct],
    'not purchasable' => [['is_purchasable' => false], PurchasingRefusal::ProductNotPurchasable],
    'not stocked' => [['track_stock' => false], PurchasingRefusal::ProductNotPurchasable],
    'a variant parent' => [['type' => 'variant_parent'], PurchasingRefusal::ProductNotPurchasable],
    'a bundle' => [['type' => 'bundle'], PurchasingRefusal::ProductNotPurchasable],
]);

it('AC-42: an unknown product id is unknown_product', function () {
    $desk = PurchasingWorld::desk();
    $order = puAddLineOrder($desk);

    puAddOrderLineRefused(PurchasingRefusal::UnknownProduct, puOrderLineFields($desk, $order, PurchasingSeed::taxGroup('ADDUP'), ['product_id' => (string) Str::ulid()]), $desk['buyer']);
});

it('AC-60, AC-61 and AC-66: a pending order is locked, an approved one is locked naming reopen, and a received, closed or cancelled one is order_closed', function (string $status, PurchasingRefusal $reason) {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDST');
    $order = puAddLineOrder($desk, ['status' => $status]);

    puAddOrderLineRefused($reason, puOrderLineFields($desk, $order, $group), $desk['buyer'], $status);
})->with([
    'pending_approval' => [OrderStatus::PendingApproval->value, PurchasingRefusal::Locked],
    'approved' => [OrderStatus::Approved->value, PurchasingRefusal::Locked],
    'partially_received' => [OrderStatus::PartiallyReceived->value, PurchasingRefusal::Locked],
    'received' => [OrderStatus::Received->value, PurchasingRefusal::OrderClosed],
    'closed' => [OrderStatus::Closed->value, PurchasingRefusal::OrderClosed],
    'cancelled' => [OrderStatus::Cancelled->value, PurchasingRefusal::OrderClosed],
]);

it('AC-51: the 200th line is accepted and the 201st is too_many_lines', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDMAX');
    $order = puAddLineOrder($desk);

    $rows = [];

    for ($i = 1; $i <= 199; $i++) {
        $rows[] = [
            'id' => (string) Str::ulid(),
            'order_id' => $order,
            'line_no' => $i,
            'product_id' => $desk['product'],
            'description' => 'Line '.$i,
            'unit_id' => $desk['unit'],
            'quantity' => '1.0000',
            'unit_factor' => '1.000000',
            'base_quantity' => '1.0000',
            'unit_cost' => '0.0000',
            'subtotal' => '0.0000',
            'discount_percent' => '0.0000',
            'discount_amount' => '0.0000',
            'tax_group_id' => $group,
            'tax_amount' => '0.0000',
            'line_total' => '0.0000',
            'received_quantity' => '0.0000',
            'received_base_quantity' => '0.0000',
        ];
    }

    PurchasingSeed::connection()->table('purchase_order_items')->insert($rows);

    $last = puAddOrderLine(puOrderLineFields($desk, $order, $group), $desk['buyer']);

    expect($last->line['line_no'])->toBe(200);

    puAddOrderLineRefused(PurchasingRefusal::TooManyLines, puOrderLineFields($desk, $order, $group), $desk['buyer'], '201st');
});

it('AC-53: an identical replay of the line id returns the original and writes nothing, and a replay of a rounded quantity is still a replay', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDRP');
    $order = puAddLineOrder($desk);
    $fields = puOrderLineFields($desk, $order, $group);

    $first = puAddOrderLine($fields, $desk['buyer']);
    $before = PurchasingRefusals::state();

    $again = puAddOrderLine(array_merge($fields, ['id' => strtolower($fields['id']), 'quantity' => 12]), $desk['buyer']);

    expect($again->replayed)->toBeTrue()
        ->and($again->line)->toBe($first->line)
        ->and($again->order)->toBe($first->order)
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::audit())->toHaveCount(1);

    // A tax group or a description the caller left out is the chain s answer, not a difference.
    expect(puAddOrderLine(array_diff_key($fields, ['tax_group_id' => 1]), $desk['buyer'])->replayed)->toBeTrue();
});

it('AC-53: the same line id with a different payload, or another order s line, is replay_conflict naming the line, and writes nothing', function (array $change) {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDRC');
    $order = puAddLineOrder($desk);
    $fields = puOrderLineFields($desk, $order, $group, ['description' => 'Same', 'expected_date' => '2026-12-01']);

    puAddOrderLine($fields, $desk['buyer']);

    if (isset($change['other_order'])) {
        $change['order_id'] = puAddLineOrder($desk);
        unset($change['other_order']);
    }

    if (isset($change['other_group'])) {
        $change['tax_group_id'] = PurchasingSeed::taxGroup('ADDRC2');
        unset($change['other_group']);
    }

    if (isset($change['other_product'])) {
        $change['product_id'] = PurchasingSeed::product('OTHER', $desk['unit']);
        unset($change['other_product']);
    }

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::ReplayConflict, fn () => puAddOrderLine(array_merge($fields, $change), $desk['buyer']));

    expect($refused->getMessage())->toContain($fields['id'])
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'another quantity' => [['quantity' => '13']],
    'another cost' => [['unit_cost' => '3.3334']],
    'another percent' => [['discount_percent' => '11']],
    'another group' => [['other_group' => true]],
    'another product' => [['other_product' => true]],
    'another description' => [['description' => 'Different']],
    'another date' => [['expected_date' => '2026-12-02']],
    'no date' => [['expected_date' => null]],
    'another order' => [['other_order' => true]],
]);

it('AC-48: a rate that changes afterwards leaves every stored line as it was, and the next line is priced at the new rate', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDFR');
    $order = puAddLineOrder($desk);

    $first = puAddOrderLine(puOrderLineFields($desk, $order, $group), $desk['buyer']);

    PurchasingSeed::connection()->table('tax_rates')->update(['rate' => '50.0000']);

    $second = puAddOrderLine(puOrderLineFields($desk, $order, $group), $desk['buyer']);

    expect(PurchasingSeed::row('purchase_order_items', $first->lineId)['tax_amount'])->toEqual(3.6)
        ->and($second->line['tax_amount'])->toBe('17.9998')
        ->and($second->order['tax_total'])->toBe('21.5998');
});

it('AC-85: the permission is asked at the stored branch, and the view key alone, a request key and no key are refused', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDAU');
    $order = puAddLineOrder($desk);
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::ORDERS_MANAGE], PurchasingSeed::branch());
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::ORDERS_VIEW], $desk['branch']);
    $requester = AccessSeed::actorHolding('requester', [PurchasingPermissions::REQUESTS_MANAGE], $desk['branch']);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puAddOrderLine(puOrderLineFields($desk, $order, $group), $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puAddOrderLine(puOrderLineFields($desk, $order, $group), $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puAddOrderLine(puOrderLineFields($desk, $order, $group), $requester));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puAddOrderLine(puOrderLineFields($desk, $order, $group), null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDSU');
    $order = puAddLineOrder($desk);
    AccessSeed::connection()->table('employees')->where('id', $desk['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puAddOrderLine(puOrderLineFields($desk, $order, $group), $desk['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('an unknown order is not_found and a malformed id is validation', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDNF');

    puAddOrderLineRefused(PurchasingRefusal::NotFound, puOrderLineFields($desk, (string) Str::ulid(), $group), $desk['buyer']);
    puAddOrderLineRefused(PurchasingRefusal::Validation, puOrderLineFields($desk, 'nope', $group), $desk['buyer']);
});

it('Ruling 7: the order header is the first statement in the write transaction and is locked for update, before the line is inserted', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDLK');
    $order = puAddLineOrder($desk);

    $log = PurchasingQueryLog::duringLocked(fn () => puAddOrderLine(puOrderLineFields($desk, $order, $group), $desk['buyer']));
    $inside = PurchasingQueryLog::inTransaction($log);

    $insert = array_values(array_filter($inside, static fn (string $sql): bool => str_starts_with($sql, 'insert into "purchase_order_items"')));

    expect($inside[0])->toContain('"purchase_orders"')->and($inside[0])->toContain('/* for update */')
        ->and($insert)->toHaveCount(1)
        ->and(array_search($insert[0], $inside, true))->toBeGreaterThan(0);
});

it('AC-94: records the measured query count of adding one line, so the cost per line is known and does not grow unnoticed', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('ADDQC');
    $order = puAddLineOrder($desk);

    $log = PurchasingQueryLog::during(fn () => puAddOrderLine(puOrderLineFields($desk, $order, $group), $desk['buyer']));

    fwrite(STDOUT, "\nAC-94 query count, AddPurchaseOrderLine: ".count($log)."\n");

    expect(count($log))->toBeLessThanOrEqual(40);
});

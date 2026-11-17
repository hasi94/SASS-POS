<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\UpdatePurchaseOrderLine;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\AddPurchaseOrderLine\AddPurchaseOrderLine;
use Flexipos\Purchasing\Features\AddPurchaseOrderLine\AddPurchaseOrderLineHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseOrderLine\UpdatePurchaseOrderLine;
use Flexipos\Purchasing\Features\UpdatePurchaseOrderLine\UpdatePurchaseOrderLineHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseOrderLine\UpdatePurchaseOrderLineResult;
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
| T-034 step 16 - UpdatePurchaseOrderLine (AC-42 to AC-49, AC-61, AC-63)
|--------------------------------------------------------------------------
|
| A new unit or quantity is resolved again; a new cost, discount or group alone
| keeps the stored quad and re-quotes it; a description or a date moves no figure;
| the status decides which of them is allowed, and the header is re-summed from the
| stored lines.
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
function puUpdateOrderLine(array $fields, ?string $actorId): UpdatePurchaseOrderLineResult
{
    /** @var UpdatePurchaseOrderLineHandler $handler */
    $handler = app(UpdatePurchaseOrderLineHandler::class);

    return $handler->handle(new UpdatePurchaseOrderLine($fields, $actorId));
}

/**
 * A draft order holding one line of the worked example (12 at 3.3333, 10 percent
 * off, 10 percent exclusive tax), written by the real add slice so every figure is
 * the engine's, in a dozen of twelve each.
 *
 * @return array{desk: array<string, string>, order: string, line: string, group: string, dozen: string, product: string, each: string}
 */
function puUpdLineWorld(string $status = 'draft'): array
{
    $desk = PurchasingWorld::desk('u1');
    $units = PurchasingSeed::unitPair('UPEA', 'UPDZ', '12');
    $product = PurchasingSeed::product('UPD-1', $units['base']);
    $group = PurchasingSeed::taxGroup('UPD');
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']]);
    $line = (string) Str::ulid();

    /** @var AddPurchaseOrderLineHandler $add */
    $add = app(AddPurchaseOrderLineHandler::class);
    $add->handle(new AddPurchaseOrderLine([
        'id' => $line,
        'order_id' => $order,
        'product_id' => $product,
        'unit_id' => $units['base'],
        'quantity' => '12',
        'unit_cost' => '3.3333',
        'discount_percent' => '10.00',
        'tax_group_id' => $group,
    ], $desk['buyer']));

    if ($status !== 'draft') {
        PurchasingSeed::connection()->table('purchase_orders')->where('id', $order)->update(['status' => $status]);
    }

    return ['desk' => $desk, 'order' => $order, 'line' => $line, 'group' => $group, 'dozen' => $units['large'], 'product' => $product, 'each' => $units['base']];
}

/**
 * @param  array<string, mixed>  $fields
 */
function puUpdateOrderLineRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puUpdateOrderLine($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-49: a new quantity resolves the quad again, re-quotes the line, re-sums the header and writes one audit row with both images', function () {
    $w = puUpdLineWorld();
    $before = PurchasingSeed::row('purchase_order_items', $w['line']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puUpdateOrderLine(['order_id' => strtolower($w['order']), 'line_id' => strtolower($w['line']), 'quantity' => '20'], strtolower($w['desk']['buyer']));

    expect($result->changed())->toBeTrue()
        ->and($result->line)->toMatchArray([
            'quantity' => '20.0000',
            'base_quantity' => '20.0000',
            'subtotal' => '66.6660',
            'discount_amount' => '6.6666',
            'tax_amount' => '5.9999',
            'line_total' => '65.9993',
        ])
        ->and($result->order)->toMatchArray([
            'subtotal' => '66.6660',
            'discount_total' => '6.6666',
            'tax_total' => '5.9999',
            'total' => '65.9993',
            'updated_at' => '2026-11-17 10:00:00.000',
        ])
        ->and($before['quantity'])->toEqual(12);

    $audit = PurchasingSeed::audit();

    expect($audit)->toHaveCount(2)
        ->and($audit[1])->toMatchArray([
            'action' => PurchasingAuditActions::ORDER_LINE_UPDATED,
            'subject_type' => 'purchase_order',
            'subject_id' => $w['order'],
            'branch_id' => $w['desk']['branch'],
            'actor_id' => $w['desk']['buyer'],
        ])
        ->and(json_decode((string) $audit[1]['before'], true)['quantity'])->toBe('12.0000')
        ->and(json_decode((string) $audit[1]['after'], true)['quantity'])->toBe('20.0000');
});

it('AC-49: a new unit is frozen fresh, and the line is priced in it', function () {
    $w = puUpdLineWorld();

    $result = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'unit_id' => $w['dozen'], 'quantity' => '2', 'unit_cost' => '10', 'discount_percent' => '0'], $w['desk']['buyer']);

    expect($result->line)->toMatchArray([
        'unit_id' => $w['dozen'],
        'quantity' => '2.0000',
        'unit_factor' => '12.000000',
        'base_quantity' => '24.0000',
        'subtotal' => '20.0000',
        'tax_amount' => '2.0000',
        'line_total' => '22.0000',
    ]);
});

it('AC-49: a cost, discount or group change alone keeps the stored quad even when the ladder has since moved, and a quantity change takes the new ladder', function () {
    $w = puUpdLineWorld();

    $moved = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'unit_id' => $w['dozen'], 'quantity' => '2'], $w['desk']['buyer']);

    expect($moved->line['unit_factor'])->toBe('12.000000');

    PurchasingSeed::connection()->table('units')->where('id', $w['dozen'])->update(['factor' => '24']);

    $priced = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'unit_cost' => '5'], $w['desk']['buyer']);

    expect($priced->line)->toMatchArray(['unit_factor' => '12.000000', 'base_quantity' => '24.0000', 'unit_cost' => '5.0000'])
        ->and($priced->changedColumns)->toEqualCanonicalizing(['unit_cost', 'subtotal', 'discount_amount', 'tax_amount', 'line_total']);

    $requantified = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'quantity' => '3'], $w['desk']['buyer']);

    expect($requantified->line)->toMatchArray(['unit_factor' => '24.000000', 'base_quantity' => '72.0000']);
});

it('AC-43 and AC-49: a discount change and a group change re-quote the stored quad, and the header follows', function () {
    $w = puUpdLineWorld();
    $inclusive = PurchasingSeed::taxGroup('UPDIN', '10.0000', true);

    $discounted = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'discount_percent' => '0'], $w['desk']['buyer']);

    expect($discounted->line)->toMatchArray(['discount_percent' => '0.0000', 'discount_amount' => '0.0000', 'tax_amount' => '4.0000', 'line_total' => '43.9996']);

    $regrouped = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'discount_percent' => '10', 'tax_group_id' => $inclusive], $w['desk']['buyer']);

    expect($regrouped->line)->toMatchArray(['tax_group_id' => $inclusive, 'tax_amount' => '3.2727', 'line_total' => '35.9996'])
        ->and($regrouped->order)->toMatchArray(['tax_total' => '3.2727', 'total' => '35.9996']);
});

it('AC-44: a changed group is checked against the directory, and a group the line already holds is priced as stored even after it was deactivated', function () {
    $w = puUpdLineWorld();

    puUpdateOrderLineRefused(PurchasingRefusal::UnknownTaxGroup, ['order_id' => $w['order'], 'line_id' => $w['line'], 'tax_group_id' => (string) Str::ulid()], $w['desk']['buyer'], 'unknown');
    puUpdateOrderLineRefused(PurchasingRefusal::InactiveTaxGroup, ['order_id' => $w['order'], 'line_id' => $w['line'], 'tax_group_id' => PurchasingSeed::taxGroup('UPDOFF', '10.0000', false, 'both', ['is_active' => false])], $w['desk']['buyer'], 'inactive');

    PurchasingSeed::connection()->table('tax_groups')->where('id', $w['group'])->update(['is_active' => false]);

    expect(puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'quantity' => '13'], $w['desk']['buyer'])->line['tax_group_id'])->toBe($w['group']);
});

it('AC-49: a description or an expected date alone changes no figure and no total, and one audit row is written', function () {
    $w = puUpdLineWorld();
    $before = PurchasingSeed::row('purchase_orders', $w['order']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'description' => ' Renamed ', 'expected_date' => '2026-12-01'], $w['desk']['buyer']);

    $after = PurchasingSeed::row('purchase_orders', $w['order']);

    expect($result->changedColumns)->toEqualCanonicalizing(['description', 'expected_date'])
        ->and($result->line)->toMatchArray(['description' => 'Renamed', 'expected_date' => '2026-12-01', 'line_total' => '39.5996'])
        ->and($after['total'])->toEqual($before['total'])
        ->and($after['updated_at'])->toBe('2026-11-17 10:00:00.000')
        ->and(PurchasingSeed::audit())->toHaveCount(2);

    $cleared = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'expected_date' => null], $w['desk']['buyer']);

    expect($cleared->line['expected_date'])->toBeNull();
});

it('AC-49: a call that changes nothing writes nothing, a value equal to the stored one is no change, and no product is judged', function () {
    $w = puUpdLineWorld();

    PurchasingSeed::connection()->table('products')->where('id', $w['product'])->update(['is_purchasable' => false]);

    $before = PurchasingRefusals::state();

    $result = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'quantity' => '12.00', 'unit_cost' => '3.3333', 'discount_percent' => '10', 'tax_group_id' => strtolower($w['group']), 'unit_id' => $w['each'], 'description' => 'Product UPD-1'], $w['desk']['buyer']);

    expect($result->changed())->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-42: a quantity change judges the product again, and a cost change alone does not', function () {
    $w = puUpdLineWorld();

    PurchasingSeed::connection()->table('products')->where('id', $w['product'])->update(['is_purchasable' => false]);

    puUpdateOrderLineRefused(PurchasingRefusal::ProductNotPurchasable, ['order_id' => $w['order'], 'line_id' => $w['line'], 'quantity' => '13'], $w['desk']['buyer']);

    expect(puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'unit_cost' => '4'], $w['desk']['buyer'])->line['unit_cost'])->toBe('4.0000');
});

it('AC-47: an edit whose line or header total cannot fit is out_of_range, and nothing changes', function () {
    $w = puUpdLineWorld();

    puUpdateOrderLineRefused(PurchasingRefusal::OutOfRange, ['order_id' => $w['order'], 'line_id' => $w['line'], 'quantity' => '99999999999999', 'unit_cost' => '99999999999999'], $w['desk']['buyer'], 'line');

    $zero = PurchasingSeed::taxGroup('UPDZ', '0.0000');
    PurchasingSeed::orderItem($w['order'], $w['product'], $w['each'], $zero, 2, ['subtotal' => '99999999999990.0000', 'line_total' => '99999999999990.0000', 'tax_amount' => '0.0000']);

    puUpdateOrderLineRefused(PurchasingRefusal::OutOfRange, ['order_id' => $w['order'], 'line_id' => $w['line'], 'unit_cost' => '99999'], $w['desk']['buyer'], 'header');
});

it('AC-91: a malformed field is validation, a field the operation does not take is validation, and nothing changes', function (array $fields) {
    $w = puUpdLineWorld();

    puUpdateOrderLineRefused(PurchasingRefusal::Validation, array_merge(['order_id' => $w['order'], 'line_id' => $w['line']], $fields), $w['desk']['buyer']);
})->with([
    'a zero quantity' => [['quantity' => '0']],
    'a float quantity' => [['quantity' => 2.5]],
    'a null quantity' => [['quantity' => null]],
    'a bad unit' => [['unit_id' => 'nope']],
    'a null unit' => [['unit_id' => null]],
    'a float cost' => [['unit_cost' => 3.5]],
    'a null cost' => [['unit_cost' => null]],
    'a negative cost' => [['unit_cost' => '-1']],
    'a percent of 100.01' => [['discount_percent' => '100.01']],
    'a null percent' => [['discount_percent' => null]],
    'a null group' => [['tax_group_id' => null]],
    'a bad group' => [['tax_group_id' => 'nope']],
    'a blank description' => [['description' => '  ']],
    'a description of 256 characters' => [['description' => str_repeat('d', 256)]],
    'a bad date' => [['expected_date' => '2026-02-30']],
    'a date before the order date' => [['expected_date' => '2026-11-15']],
    'a product' => [['product_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0']],
    'a line total' => [['line_total' => '1.0000']],
    'a received quantity' => [['received_base_quantity' => '1.0000']],
]);

it('AC-91: an unknown order or line is not_found, another order s line is not_found through this one, and a malformed id is validation', function () {
    $w = puUpdLineWorld();
    $other = PurchasingSeed::order($w['desk']['supplier'], $w['desk']['branch'], $w['desk']['location']);

    puUpdateOrderLineRefused(PurchasingRefusal::NotFound, ['order_id' => (string) Str::ulid(), 'line_id' => $w['line'], 'description' => 'x'], $w['desk']['buyer']);
    puUpdateOrderLineRefused(PurchasingRefusal::NotFound, ['order_id' => $w['order'], 'line_id' => (string) Str::ulid(), 'description' => 'x'], $w['desk']['buyer']);
    puUpdateOrderLineRefused(PurchasingRefusal::NotFound, ['order_id' => $other, 'line_id' => $w['line'], 'description' => 'x'], $w['desk']['buyer']);
    puUpdateOrderLineRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'line_id' => 'nope', 'description' => 'x'], $w['desk']['buyer']);
    puUpdateOrderLineRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'description' => 'x'], $w['desk']['buyer']);
});

it('AC-60 and AC-66: a pending order is locked, and a received, closed or cancelled one is order_closed, for any field and for a call that changes nothing', function (string $status, PurchasingRefusal $reason) {
    $w = puUpdLineWorld($status);

    puUpdateOrderLineRefused($reason, ['order_id' => $w['order'], 'line_id' => $w['line'], 'quantity' => '13'], $w['desk']['buyer'], $status);
    puUpdateOrderLineRefused($reason, ['order_id' => $w['order'], 'line_id' => $w['line'], 'description' => 'Product UPD-1'], $w['desk']['buyer'], $status.' unchanged');
})->with([
    'pending_approval' => [OrderStatus::PendingApproval->value, PurchasingRefusal::Locked],
    'received' => [OrderStatus::Received->value, PurchasingRefusal::OrderClosed],
    'closed' => [OrderStatus::Closed->value, PurchasingRefusal::OrderClosed],
    'cancelled' => [OrderStatus::Cancelled->value, PurchasingRefusal::OrderClosed],
]);

it('AC-61: an approved order refuses any field but the line s expected date as locked, naming the field and reopen, and takes the date', function () {
    $w = puUpdLineWorld('approved');

    $refused = PurchasingRefusals::expect(PurchasingRefusal::Locked, fn () => puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'quantity' => '13'], $w['desk']['buyer']));

    expect($refused->getMessage())->toContain('quantity')->and($refused->getMessage())->toContain('Reopen');

    puUpdateOrderLineRefused(PurchasingRefusal::Locked, ['order_id' => $w['order'], 'line_id' => $w['line'], 'description' => 'New'], $w['desk']['buyer'], 'description');

    $result = puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'expected_date' => '2026-12-05'], $w['desk']['buyer']);

    expect($result->line['expected_date'])->toBe('2026-12-05')
        ->and($result->line['line_total'])->toBe('39.5996')
        ->and(PurchasingSeed::audit())->toHaveCount(2);
});

it('AC-63: a partly received order takes the expected date of a line that is not yet complete, and refuses that of a complete one', function () {
    $w = puUpdLineWorld('partially_received');
    $complete = PurchasingSeed::orderItem($w['order'], $w['product'], $w['each'], $w['group'], 2, ['received_quantity' => '1.0000', 'received_base_quantity' => '1.0000']);

    expect(puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'expected_date' => '2026-12-05'], $w['desk']['buyer'])->line['expected_date'])->toBe('2026-12-05');

    puUpdateOrderLineRefused(PurchasingRefusal::Locked, ['order_id' => $w['order'], 'line_id' => $complete, 'expected_date' => '2026-12-05'], $w['desk']['buyer'], 'complete line');
});

it('AC-85: the permission is asked at the stored branch, and the view key alone, a request key and no key are refused', function () {
    $w = puUpdLineWorld();
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::ORDERS_MANAGE], PurchasingSeed::branch());
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::ORDERS_VIEW], $w['desk']['branch']);
    $requester = AccessSeed::actorHolding('requester', [PurchasingPermissions::REQUESTS_MANAGE], $w['desk']['branch']);
    $fields = ['order_id' => $w['order'], 'line_id' => $w['line'], 'description' => 'x'];

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateOrderLine($fields, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateOrderLine($fields, $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateOrderLine($fields, $requester));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puUpdateOrderLine($fields, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $w = puUpdLineWorld();
    AccessSeed::connection()->table('employees')->where('id', $w['desk']['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'description' => 'x'], $w['desk']['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('Ruling 7: the order header is locked first, then the line, inside the transaction', function () {
    $w = puUpdLineWorld();

    $log = PurchasingQueryLog::duringLocked(fn () => puUpdateOrderLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'quantity' => '13'], $w['desk']['buyer']));
    $inside = PurchasingQueryLog::inTransaction($log);

    $lineLock = array_values(array_filter($inside, static fn (string $sql): bool => str_contains($sql, '"purchase_order_items"') && str_contains($sql, '/* for update */')));

    expect($inside[0])->toContain('"purchase_orders"')->and($inside[0])->toContain('/* for update */')
        ->and($lineLock)->not->toBe([])
        ->and(array_search($lineLock[0], $inside, true))->toBeGreaterThan(0);
});

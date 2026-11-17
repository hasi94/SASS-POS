<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\UpdatePurchaseOrder;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\UpdatePurchaseOrder\UpdatePurchaseOrder;
use Flexipos\Purchasing\Features\UpdatePurchaseOrder\UpdatePurchaseOrderHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseOrder\UpdatePurchaseOrderResult;
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
| T-034 step 15 - UpdatePurchaseOrder, the draft half (AC-12, AC-41, AC-50)
|--------------------------------------------------------------------------
|
| A draft takes every header field; a supplier change re-copies the snapshots; a
| shipping change re-totals; a locked status refuses before anything is judged; a
| call that changes nothing writes nothing.
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
function puUpdateOrder(array $fields, ?string $actorId): UpdatePurchaseOrderResult
{
    /** @var UpdatePurchaseOrderHandler $handler */
    $handler = app(UpdatePurchaseOrderHandler::class);

    return $handler->handle(new UpdatePurchaseOrder($fields, $actorId));
}

/**
 * A draft order on the desk.
 *
 * @param  array<string, string>  $desk
 * @param  array<string, mixed>  $overrides
 */
function puDraftOrder(array $desk, array $overrides = []): string
{
    return PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], array_merge(['created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']], $overrides));
}

/**
 * @param  array<string, mixed>  $fields
 */
function puUpdateOrderRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puUpdateOrder($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-50: a draft takes every header field, moves updated_by and updated_at, and writes one audit row with the before and after images', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk, ['notes' => 'old']);
    $newLocation = PurchasingSeed::location($desk['branch'], 'NEW');
    $editor = AccessSeed::actorHolding('editor', [PurchasingPermissions::ORDERS_MANAGE], $desk['branch']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puUpdateOrder([
        'order_id' => strtolower($order),
        'location_id' => $newLocation,
        'expected_date' => '2026-12-01',
        'currency' => 'eur',
        'exchange_rate' => '1.5',
        'shipping' => '3',
        'terms' => ' Net 45 ',
        'notes' => 'new',
    ], strtolower($editor));

    expect($result->changed())->toBeTrue()
        ->and($result->changedColumns)->toEqualCanonicalizing(['location_id', 'expected_date', 'currency', 'exchange_rate', 'shipping', 'terms', 'notes', 'subtotal', 'discount_total', 'tax_total', 'total'])
        ->and(PurchasingSeed::row('purchase_orders', $order))->toMatchArray([
            'location_id' => $newLocation,
            'expected_date' => '2026-12-01',
            'currency' => 'EUR',
            'terms' => 'Net 45',
            'notes' => 'new',
            'updated_by' => $editor,
            'updated_at' => '2026-11-17 10:00:00.000',
            'created_by' => $desk['buyer'],
            'status' => 'draft',
        ])
        ->and($result->order)->toMatchArray(['exchange_rate' => '1.50000000', 'shipping' => '3.0000', 'total' => '3.0000']);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $editor,
        'branch_id' => $desk['branch'],
        'module' => PurchasingAuditActions::MODULE,
        'action' => PurchasingAuditActions::ORDER_UPDATED,
        'subject_type' => 'purchase_order',
        'subject_id' => $order,
    ])
        ->and($audit['before']['notes'])->toBe('old')
        ->and($audit['after']['notes'])->toBe('new')
        ->and($audit['after']['currency'])->toBe('EUR');
});

it('AC-50: a field left out keeps its value, and an explicit null clears expected_date, terms and notes', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk, ['expected_date' => '2026-12-01', 'terms' => 't', 'notes' => 'n']);

    $kept = puUpdateOrder(['order_id' => $order, 'notes' => 'changed'], $desk['buyer']);
    $cleared = puUpdateOrder(['order_id' => $order, 'expected_date' => null, 'terms' => null, 'notes' => ' '], $desk['buyer']);

    expect($kept->order['terms'])->toBe('t')
        ->and($kept->order['expected_date'])->toBe('2026-12-01')
        ->and($cleared->order['expected_date'])->toBeNull()
        ->and($cleared->order['terms'])->toBeNull()
        ->and($cleared->order['notes'])->toBeNull();
});

it('AC-50: a supplier change re-copies the three snapshots and leaves the currency as stored, unless the call names one', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk, ['currency' => 'LKR']);
    $other = PurchasingSeed::supplier('OTH', ['name' => 'Other Co', 'tax_no' => 'TIN-2', 'payment_terms_days' => 14, 'currency' => 'USD']);

    $moved = puUpdateOrder(['order_id' => $order, 'supplier_id' => $other], $desk['buyer']);

    expect($moved->order)->toMatchArray([
        'supplier_id' => $other,
        'supplier_name' => 'Other Co',
        'supplier_tax_no' => 'TIN-2',
        'payment_terms_days' => 14,
        'currency' => 'LKR',
    ]);

    $named = puUpdateOrder(['order_id' => $order, 'supplier_id' => $desk['supplier'], 'currency' => 'USD'], $desk['buyer']);

    expect($named->order['currency'])->toBe('USD')
        ->and($named->order['supplier_name'])->toBe('Supplier '.'SD1');
});

it('AC-41: a supplier change to an unknown, inactive or soft-deleted supplier is refused, and nothing changes', function (string $why, PurchasingRefusal $reason) {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk);

    $supplier = match ($why) {
        'unknown' => (string) Str::ulid(),
        'inactive' => PurchasingSeed::supplier('OFF', ['is_active' => false]),
        default => PurchasingSeed::supplier('DEL', ['deleted_at' => PurchasingSeed::NOW]),
    };

    puUpdateOrderRefused($reason, ['order_id' => $order, 'supplier_id' => $supplier, 'notes' => 'x'], $desk['buyer'], $why);
})->with([
    'unknown' => ['unknown', PurchasingRefusal::UnknownSupplier],
    'inactive' => ['inactive', PurchasingRefusal::SupplierInactive],
    'soft-deleted' => ['soft-deleted', PurchasingRefusal::UnknownSupplier],
]);

it('AC-13: a supplier that is merely kept is not judged again, so an order of a deactivated supplier can still have its notes edited', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk);
    PurchasingSeed::connection()->table('suppliers')->where('id', $desk['supplier'])->update(['is_active' => false]);

    $result = puUpdateOrder(['order_id' => $order, 'supplier_id' => $desk['supplier'], 'notes' => 'still editable'], $desk['buyer']);

    expect($result->order['notes'])->toBe('still editable')
        ->and($result->changedColumns)->toBe(['notes']);
});

it('AC-12: an edit to the supplier elsewhere never changes a stored order s snapshots, and an order edit that keeps the supplier does not re-copy them', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk, ['supplier_name' => 'Name at the time', 'supplier_tax_no' => 'OLD-TIN', 'payment_terms_days' => 7]);

    PurchasingSeed::connection()->table('suppliers')->where('id', $desk['supplier'])->update(['name' => 'Renamed', 'tax_no' => 'NEW-TIN', 'payment_terms_days' => 90]);

    $result = puUpdateOrder(['order_id' => $order, 'supplier_id' => $desk['supplier'], 'notes' => 'x'], $desk['buyer']);

    expect($result->order)->toMatchArray(['supplier_name' => 'Name at the time', 'supplier_tax_no' => 'OLD-TIN', 'payment_terms_days' => 7]);
});

it('AC-45 and AC-50: a shipping change re-totals from the stored lines, and a total that cannot fit refuses the change', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('UPD');
    $order = puDraftOrder($desk, ['shipping' => '2.5', 'subtotal' => '20.0000', 'discount_total' => '0.0000', 'tax_total' => '2.0000', 'total' => '24.5000']);
    PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 1);
    PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 2);

    $result = puUpdateOrder(['order_id' => $order, 'shipping' => '5'], $desk['buyer']);

    expect($result->order)->toMatchArray(['shipping' => '5.0000', 'subtotal' => '20.0000', 'tax_total' => '2.0000', 'total' => '27.0000']);

    PurchasingSeed::connection()->table('purchase_order_items')->where('order_id', $order)->where('line_no', 1)->update(['line_total' => '99999999999999.0000']);

    puUpdateOrderRefused(PurchasingRefusal::OutOfRange, ['order_id' => $order, 'shipping' => '99999999999999'], $desk['buyer']);
});

it('AC-50: a call that changes nothing writes nothing, not even updated_at, and a value equal to the stored one is no change', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk, ['notes' => 'same', 'expected_date' => '2026-12-01', 'exchange_rate' => '1.50000000']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-18 10:00:00.000'));

    $before = PurchasingRefusals::state();

    $result = puUpdateOrder(['order_id' => $order, 'notes' => 'same', 'expected_date' => '2026-12-01', 'exchange_rate' => '1.5', 'location_id' => $desk['location'], 'shipping' => '0', 'currency' => 'lkr'], $desk['buyer']);

    expect($result->changed())->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-41: the location must be an eligible delivery location of the order s branch, and the date must not be before the order date', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk);

    puUpdateOrderRefused(PurchasingRefusal::UnknownLocation, ['order_id' => $order, 'location_id' => PurchasingSeed::location(null, 'CENTRAL')], $desk['buyer'], 'central');
    puUpdateOrderRefused(PurchasingRefusal::UnknownLocation, ['order_id' => $order, 'location_id' => PurchasingSeed::location(PurchasingSeed::branch(), 'ELSE')], $desk['buyer'], 'another branch');
    puUpdateOrderRefused(PurchasingRefusal::UnknownLocation, ['order_id' => $order, 'location_id' => (string) Str::ulid()], $desk['buyer'], 'unknown');
    puUpdateOrderRefused(PurchasingRefusal::Validation, ['order_id' => $order, 'expected_date' => '2026-11-15'], $desk['buyer'], 'before the order date');

    expect(puUpdateOrder(['order_id' => $order, 'expected_date' => '2026-11-16'], $desk['buyer'])->order['expected_date'])->toBe('2026-11-16');
});

it('AC-91: a malformed field is validation, a field the operation does not take is validation, and nothing changes', function (array $fields) {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk);

    puUpdateOrderRefused(PurchasingRefusal::Validation, array_merge(['order_id' => $order], $fields), $desk['buyer']);
})->with([
    'a null supplier' => [['supplier_id' => null]],
    'a bad supplier' => [['supplier_id' => 'nope']],
    'a bad location' => [['location_id' => 'nope']],
    'a bad date' => [['expected_date' => '2026-02-30']],
    'a null currency' => [['currency' => null]],
    'a bad currency' => [['currency' => 'EURO']],
    'a null rate' => [['exchange_rate' => null]],
    'a zero rate' => [['exchange_rate' => '0']],
    'nine decimals of rate' => [['exchange_rate' => '1.123456789']],
    'a float rate' => [['exchange_rate' => 1.5]],
    'a null shipping' => [['shipping' => null]],
    'a negative shipping' => [['shipping' => '-1']],
    'a float shipping' => [['shipping' => 2.5]],
    'terms of 1001 characters' => [['terms' => str_repeat('t', 1001)]],
    'notes of 2001 characters' => [['notes' => str_repeat('n', 2001)]],
    'a branch' => [['branch_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0']],
    'a number' => [['number' => 'PO9']],
    'a status' => [['status' => 'approved']],
    'a total' => [['total' => '1.0000']],
    'a supplier name' => [['supplier_name' => 'Mine']],
]);

it('AC-91: an unknown order is not_found and a malformed or missing id is validation', function () {
    $desk = PurchasingWorld::desk();

    puUpdateOrderRefused(PurchasingRefusal::NotFound, ['order_id' => (string) Str::ulid(), 'notes' => 'x'], $desk['buyer']);
    puUpdateOrderRefused(PurchasingRefusal::Validation, ['order_id' => 'nope', 'notes' => 'x'], $desk['buyer']);
    puUpdateOrderRefused(PurchasingRefusal::Validation, ['notes' => 'x'], $desk['buyer']);
});

it('AC-60 and AC-66: a pending order is locked, and a received, closed or cancelled one is order_closed, whatever the field and even for a call that changes nothing', function (string $status, PurchasingRefusal $reason) {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk, ['status' => $status, 'notes' => 'n']);

    puUpdateOrderRefused($reason, ['order_id' => $order, 'notes' => 'changed'], $desk['buyer'], $status);
    puUpdateOrderRefused($reason, ['order_id' => $order, 'notes' => 'n'], $desk['buyer'], $status.' unchanged');
})->with([
    'pending_approval' => [OrderStatus::PendingApproval->value, PurchasingRefusal::Locked],
    'sent' => [OrderStatus::Sent->value, PurchasingRefusal::Locked],
    'received' => [OrderStatus::Received->value, PurchasingRefusal::OrderClosed],
    'closed' => [OrderStatus::Closed->value, PurchasingRefusal::OrderClosed],
    'cancelled' => [OrderStatus::Cancelled->value, PurchasingRefusal::OrderClosed],
]);

it('AC-61: an approved order refuses any other field as locked naming the field and reopen, and takes notes, terms and the expected date', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk, ['status' => 'approved']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::Locked, fn () => puUpdateOrder(['order_id' => $order, 'shipping' => '5'], $desk['buyer']));

    expect($refused->getMessage())->toContain('shipping')->and($refused->getMessage())->toContain('Reopen');

    $result = puUpdateOrder(['order_id' => $order, 'notes' => 'amended', 'terms' => 'Net 10', 'expected_date' => '2026-12-05'], $desk['buyer']);

    expect($result->order)->toMatchArray(['notes' => 'amended', 'terms' => 'Net 10', 'expected_date' => '2026-12-05', 'status' => 'approved']);
});

it('AC-85: the permission is asked at the stored branch, and the view key alone, a request key and no key are refused', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk);
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::ORDERS_MANAGE], PurchasingSeed::branch());
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::ORDERS_VIEW], $desk['branch']);
    $requester = AccessSeed::actorHolding('requester', [PurchasingPermissions::REQUESTS_MANAGE], $desk['branch']);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateOrder(['order_id' => $order, 'notes' => 'x'], $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateOrder(['order_id' => $order, 'notes' => 'x'], $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateOrder(['order_id' => $order, 'notes' => 'x'], $requester));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puUpdateOrder(['order_id' => $order, 'notes' => 'x'], null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk);
    AccessSeed::connection()->table('employees')->where('id', $desk['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puUpdateOrder(['order_id' => $order, 'notes' => 'x'], $desk['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('Ruling 7: the order header is the first statement in the transaction and is locked for update, and a new supplier is share-locked after it', function () {
    $desk = PurchasingWorld::desk();
    $order = puDraftOrder($desk);
    $other = PurchasingSeed::supplier('LCK');

    $log = PurchasingQueryLog::duringLocked(fn () => puUpdateOrder(['order_id' => $order, 'supplier_id' => $other], $desk['buyer']));
    $inside = PurchasingQueryLog::inTransaction($log);

    $supplier = array_values(array_filter($inside, static fn (string $sql): bool => str_contains($sql, '"suppliers"')));

    expect($inside[0])->toContain('"purchase_orders"')->and($inside[0])->toContain('/* for update */')
        ->and($supplier)->not->toBe([])
        ->and($supplier[0])->toContain('/* lock in share mode */')
        ->and(array_search($supplier[0], $inside, true))->toBeGreaterThan(0);
});

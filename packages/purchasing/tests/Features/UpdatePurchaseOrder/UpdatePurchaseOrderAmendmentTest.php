<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\UpdatePurchaseOrder;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\AddPurchaseOrderLine\AddPurchaseOrderLine;
use Flexipos\Purchasing\Features\AddPurchaseOrderLine\AddPurchaseOrderLineHandler;
use Flexipos\Purchasing\Features\RemovePurchaseOrderLine\RemovePurchaseOrderLine;
use Flexipos\Purchasing\Features\RemovePurchaseOrderLine\RemovePurchaseOrderLineHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseOrder\UpdatePurchaseOrder;
use Flexipos\Purchasing\Features\UpdatePurchaseOrder\UpdatePurchaseOrderHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseOrderLine\UpdatePurchaseOrderLine;
use Flexipos\Purchasing\Features\UpdatePurchaseOrderLine\UpdatePurchaseOrderLineHandler;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 19b - the amendment table on an approved order (AC-60, AC-61, AC-63, AC-66)
|--------------------------------------------------------------------------
|
| Every header and line field against every status, through the real update, add and
| remove slices: draft takes all; approved and partially_received take only notes,
| terms and the expected dates (a complete line's date is locked); pending_approval is
| locked for everything; received, closed and cancelled are order_closed. A post-approval
| edit is audited under the same action a draft edit uses (`*_updated`, Ruling 12).
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
 * An order in this status with one line of 1.0000 at 10.0000, and the other things a
 * changed field can point at (a second supplier, location, unit and tax group).
 *
 * @return array{desk: array<string, string>, order: string, line: string, supplier: string, location: string, unit: string, group: string}
 */
function puAmendWorld(string $status, bool $lineComplete = false): array
{
    $desk = PurchasingWorld::desk('m1');
    $group = PurchasingSeed::taxGroup('AMA');
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], [
        'status' => $status,
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'total' => '11.0000',
    ]);
    $line = PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 1, $lineComplete ? ['received_quantity' => '1.0000', 'received_base_quantity' => '1.0000'] : []);

    return [
        'desk' => $desk,
        'order' => $order,
        'line' => $line,
        'supplier' => PurchasingSeed::supplier('AMB'),
        'location' => PurchasingSeed::location($desk['branch'], 'AMLOC'),
        'unit' => PricingSeed::unit('AMU'),
        'group' => PurchasingSeed::taxGroup('AMB'),
    ];
}

/**
 * The change one header field would make, always a real change from what is stored.
 *
 * @param  array{supplier: string, location: string}  $w
 */
function puAmendHeaderValue(string $field, array $w): string
{
    return match ($field) {
        'supplier_id' => $w['supplier'],
        'location_id' => $w['location'],
        'currency' => 'EUR',
        'exchange_rate' => '2',
        'shipping' => '5',
        'terms' => 'Net 45',
        'notes' => 'a note',
        default => '2026-12-01',
    };
}

/**
 * @param  array{unit: string, group: string}  $w
 */
function puAmendLineValue(string $field, array $w): string
{
    return match ($field) {
        'quantity' => '2',
        'unit_id' => $w['unit'],
        'unit_cost' => '5',
        'discount_percent' => '5.00',
        'tax_group_id' => $w['group'],
        'description' => 'changed',
        default => '2026-12-01',
    };
}

/**
 * @param  array<string, mixed>  $fields
 */
function puAmendOrder(array $fields, string $actor): void
{
    app(UpdatePurchaseOrderHandler::class)->handle(new UpdatePurchaseOrder($fields, $actor));
}

/**
 * @param  array<string, mixed>  $fields
 */
function puAmendLine(array $fields, string $actor): void
{
    app(UpdatePurchaseOrderLineHandler::class)->handle(new UpdatePurchaseOrderLine($fields, $actor));
}

it('AC-61 and AC-63: an approved or partly received order takes notes, terms and the expected date, each with one updated audit row', function (string $status, string $field) {
    $w = puAmendWorld($status);

    puAmendOrder(['order_id' => $w['order'], $field => puAmendHeaderValue($field, $w)], $w['desk']['buyer']);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::ORDER_UPDATED)
        ->and($audit['before']['status'])->toBe($status)
        ->and($audit['after']['status'])->toBe($status)
        ->and($audit['changed_fields'])->toContain($field);
})->with(function (): array {
    $rows = [];

    foreach (['approved', 'partially_received'] as $status) {
        foreach (['notes', 'terms', 'expected_date'] as $field) {
            $rows["{$status} {$field}"] = [$status, $field];
        }
    }

    return $rows;
});

it('AC-61 and AC-63: an approved or partly received order refuses every other header field as locked naming the field, and approved names reopen', function (string $status, string $field) {
    $w = puAmendWorld($status);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(
        PurchasingRefusal::Locked,
        fn () => puAmendOrder(['order_id' => $w['order'], $field => puAmendHeaderValue($field, $w)], $w['desk']['buyer']),
        "{$status} {$field}",
    );

    expect($refused->getMessage())->toContain($field)
        ->and(str_contains($refused->getMessage(), 'Reopen'))->toBe($status === 'approved')
        ->and(PurchasingRefusals::state())->toBe($before);
})->with(function (): array {
    $rows = [];

    foreach (['approved', 'partially_received'] as $status) {
        foreach (['supplier_id', 'location_id', 'currency', 'exchange_rate', 'shipping'] as $field) {
            $rows["{$status} {$field}"] = [$status, $field];
        }
    }

    return $rows;
});

it('AC-60 and AC-66: pending_approval is locked for every header field, and received, closed and cancelled are order_closed', function (string $status, PurchasingRefusal $reason, string $field) {
    $w = puAmendWorld($status);
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(
        $reason,
        fn () => puAmendOrder(['order_id' => $w['order'], $field => puAmendHeaderValue($field, $w)], $w['desk']['buyer']),
        "{$status} {$field}",
    );

    expect(PurchasingRefusals::state())->toBe($before);
})->with(function (): array {
    $rows = [];

    foreach ([['pending_approval', PurchasingRefusal::Locked], ['received', PurchasingRefusal::OrderClosed], ['closed', PurchasingRefusal::OrderClosed], ['cancelled', PurchasingRefusal::OrderClosed]] as [$status, $reason]) {
        foreach (['supplier_id', 'location_id', 'currency', 'exchange_rate', 'shipping', 'notes', 'terms', 'expected_date'] as $field) {
            $rows["{$status} {$field}"] = [$status, $reason, $field];
        }
    }

    return $rows;
});

it('AC-61 and AC-63: a line s expected date can be changed on an approved order and on a partly received order while the line is incomplete, audited as a line update', function (string $status) {
    $w = puAmendWorld($status);

    puAmendLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'expected_date' => '2026-12-01'], $w['desk']['buyer']);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::ORDER_LINE_UPDATED)
        ->and($audit['changed_fields'])->toContain('expected_date')
        ->and(PurchasingSeed::row('purchase_order_items', $w['line'])['expected_date'])->toBe('2026-12-01')
        ->and(PurchasingSeed::row('purchase_orders', $w['order'])['total'])->toEqual(11);
})->with([
    'approved' => ['approved'],
    'partially_received' => ['partially_received'],
]);

it('AC-63: the expected date of a complete line is locked on a partly received order', function () {
    $w = puAmendWorld('partially_received', true);
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::Locked, fn () => puAmendLine(['order_id' => $w['order'], 'line_id' => $w['line'], 'expected_date' => '2026-12-01'], $w['desk']['buyer']));

    expect(PurchasingRefusals::state())->toBe($before);
});

it('AC-61 and AC-63: every other line field is locked on an approved or partly received order, naming the field', function (string $status, string $field) {
    $w = puAmendWorld($status);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(
        PurchasingRefusal::Locked,
        fn () => puAmendLine(['order_id' => $w['order'], 'line_id' => $w['line'], $field => puAmendLineValue($field, $w)], $w['desk']['buyer']),
        "{$status} {$field}",
    );

    expect($refused->getMessage())->toContain($field)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with(function (): array {
    $rows = [];

    foreach (['approved', 'partially_received'] as $status) {
        foreach (['quantity', 'unit_id', 'unit_cost', 'discount_percent', 'tax_group_id', 'description'] as $field) {
            $rows["{$status} {$field}"] = [$status, $field];
        }
    }

    return $rows;
});

it('AC-60 and AC-66: every line field of a pending order is locked, and of a received, closed or cancelled order order_closed', function (string $status, PurchasingRefusal $reason, string $field) {
    $w = puAmendWorld($status);
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(
        $reason,
        fn () => puAmendLine(['order_id' => $w['order'], 'line_id' => $w['line'], $field => puAmendLineValue($field, $w)], $w['desk']['buyer']),
        "{$status} {$field}",
    );

    expect(PurchasingRefusals::state())->toBe($before);
})->with(function (): array {
    $rows = [];

    foreach ([['pending_approval', PurchasingRefusal::Locked], ['received', PurchasingRefusal::OrderClosed], ['closed', PurchasingRefusal::OrderClosed], ['cancelled', PurchasingRefusal::OrderClosed]] as [$status, $reason]) {
        foreach (['quantity', 'unit_id', 'unit_cost', 'discount_percent', 'tax_group_id', 'description', 'expected_date'] as $field) {
            $rows["{$status} {$field}"] = [$status, $reason, $field];
        }
    }

    return $rows;
});

it('AC-61, AC-60 and AC-66: adding or removing a line is locked outside draft (approved names reopen), and order_closed on a closed order', function (string $status, PurchasingRefusal $reason) {
    $w = puAmendWorld($status);
    $before = PurchasingRefusals::state();

    $added = PurchasingRefusals::expect($reason, fn () => app(AddPurchaseOrderLineHandler::class)->handle(new AddPurchaseOrderLine([
        'id' => (string) Str::ulid(),
        'order_id' => $w['order'],
        'product_id' => $w['desk']['product'],
        'unit_id' => $w['desk']['unit'],
        'quantity' => '1',
        'unit_cost' => '1',
        'tax_group_id' => $w['group'],
    ], $w['desk']['buyer'])), "add {$status}");

    $removed = PurchasingRefusals::expect($reason, fn () => app(RemovePurchaseOrderLineHandler::class)->handle(new RemovePurchaseOrderLine([
        'order_id' => $w['order'],
        'line_id' => $w['line'],
    ], $w['desk']['buyer'])), "remove {$status}");

    expect(str_contains($added->getMessage(), 'Reopen'))->toBe($status === 'approved')
        ->and(str_contains($removed->getMessage(), 'Reopen'))->toBe($status === 'approved')
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'approved' => ['approved', PurchasingRefusal::Locked],
    'partially_received' => ['partially_received', PurchasingRefusal::Locked],
    'pending_approval' => ['pending_approval', PurchasingRefusal::Locked],
    'received' => ['received', PurchasingRefusal::OrderClosed],
    'closed' => ['closed', PurchasingRefusal::OrderClosed],
    'cancelled' => ['cancelled', PurchasingRefusal::OrderClosed],
]);

it('AC-61: a reopened order takes every field again, and its header edit is the same updated action', function () {
    $w = puAmendWorld('draft');

    puAmendOrder(['order_id' => $w['order'], 'shipping' => '5', 'supplier_id' => $w['supplier']], $w['desk']['buyer']);

    expect(PurchasingSeed::onlyAuditRow()['action'])->toBe(PurchasingAuditActions::ORDER_UPDATED);
});

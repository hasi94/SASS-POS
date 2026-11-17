<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\GetPurchaseOrder;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\GetPurchaseOrder\GetPurchaseOrder;
use Flexipos\Purchasing\Features\GetPurchaseOrder\GetPurchaseOrderHandler;
use Flexipos\Purchasing\Features\GetPurchaseOrder\GetPurchaseOrderResult;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 18 - GetPurchaseOrder (AC-48, AC-81, AC-83, AC-84, AC-86, D-36)
|--------------------------------------------------------------------------
|
| The header and every line as stored, every amount a four-decimal string, the
| supplier the order's own snapshot, the view key asked at the stored branch, and
| nothing recomputed on read.
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

function puGetOrder(string $orderId, ?string $actorId): GetPurchaseOrderResult
{
    /** @var GetPurchaseOrderHandler $handler */
    $handler = app(GetPurchaseOrderHandler::class);

    return $handler->handle(new GetPurchaseOrder($orderId, $actorId));
}

it('AC-81: returns the header and the lines by line number, as stored, with every decimal a four-decimal string and the three supplier snapshots', function () {
    $desk = PurchasingWorld::desk();
    $group = PurchasingSeed::taxGroup('GETO');
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], [
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'supplier_name' => 'Name at the time',
        'supplier_tax_no' => 'TIN-1',
        'payment_terms_days' => 30,
        'request_id' => $request,
        'expected_date' => '2026-12-01',
        'status' => 'approved',
        'currency' => 'USD',
        'exchange_rate' => '1.25000000',
        'subtotal' => '30.0000',
        'discount_total' => '1.0000',
        'tax_total' => '3.0000',
        'shipping' => '2.5000',
        'total' => '34.5000',
        'terms' => 'Net 30',
        'notes' => 'Rush',
    ]);
    $second = PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 2, ['quantity' => '2.0000', 'base_quantity' => '2.0000']);
    $first = PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group, 1, [
        'description' => 'Widgets',
        'quantity' => '5.0000',
        'unit_factor' => '12.000000',
        'base_quantity' => '60.0000',
        'unit_cost' => '2.5000',
        'subtotal' => '12.5000',
        'discount_percent' => '10.0000',
        'discount_amount' => '1.2500',
        'tax_amount' => '1.1250',
        'line_total' => '12.3750',
        'received_quantity' => '1.0000',
        'received_base_quantity' => '12.0000',
        'expected_date' => '2026-12-05',
    ]);

    $result = puGetOrder(strtolower($order), strtolower($desk['buyer']));

    expect($result->order)->toBe([
        'id' => $order,
        'created_at' => PurchasingSeed::NOW,
        'updated_at' => PurchasingSeed::NOW,
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'number' => PurchasingSeed::row('purchase_orders', $order)['number'],
        'supplier_id' => $desk['supplier'],
        'supplier_name' => 'Name at the time',
        'supplier_tax_no' => 'TIN-1',
        'payment_terms_days' => 30,
        'branch_id' => $desk['branch'],
        'location_id' => $desk['location'],
        'request_id' => $request,
        'order_date' => PurchasingSeed::DATE,
        'expected_date' => '2026-12-01',
        'status' => 'approved',
        'workflow_instance_id' => null,
        'currency' => 'USD',
        'exchange_rate' => '1.25000000',
        'subtotal' => '30.0000',
        'discount_total' => '1.0000',
        'tax_total' => '3.0000',
        'shipping' => '2.5000',
        'total' => '34.5000',
        'received_value' => '0.0000',
        'terms' => 'Net 30',
        'notes' => 'Rush',
    ])
        ->and(array_column($result->lines, 'id'))->toBe([$first, $second])
        ->and($result->lines[0])->toBe([
            'id' => $first,
            'order_id' => $order,
            'line_no' => 1,
            'product_id' => $desk['product'],
            'description' => 'Widgets',
            'unit_id' => $desk['unit'],
            'quantity' => '5.0000',
            'unit_factor' => '12.000000',
            'base_quantity' => '60.0000',
            'unit_cost' => '2.5000',
            'subtotal' => '12.5000',
            'discount_percent' => '10.0000',
            'discount_amount' => '1.2500',
            'tax_group_id' => $group,
            'tax_amount' => '1.1250',
            'line_total' => '12.3750',
            'received_quantity' => '1.0000',
            'received_base_quantity' => '12.0000',
            'expected_date' => '2026-12-05',
        ])
        ->and($result->lines[1]['expected_date'])->toBeNull();

    foreach (array_merge(array_values($result->order), ...array_map('array_values', $result->lines)) as $value) {
        expect(is_float($value))->toBeFalse();
    }
});

it('AC-81: an order with no lines has an empty list', function () {
    $desk = PurchasingWorld::desk();
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location']);

    expect(puGetOrder($order, $desk['buyer'])->lines)->toBe([]);
});

it('AC-48: the stored figures are returned whatever a rate, the ladder, the product, the group or the supplier has become since, and the read writes nothing', function () {
    $desk = PurchasingWorld::desk();
    $units = PurchasingSeed::unitPair('EAGO', 'DZGO', '12');
    $product = PurchasingSeed::product('GET-O-LADDER', $units['base']);
    $group = PurchasingSeed::taxGroup('GETOFR');
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['subtotal' => '10.0000', 'tax_total' => '1.0000', 'total' => '11.0000']);
    PurchasingSeed::orderItem($order, $product, $units['large'], $group, 1, ['quantity' => '1.0000', 'unit_factor' => '12.000000', 'base_quantity' => '12.0000']);

    $before = puGetOrder($order, $desk['buyer']);

    PurchasingSeed::connection()->table('units')->where('id', $units['large'])->update(['factor' => '24']);
    PurchasingSeed::connection()->table('tax_rates')->update(['rate' => '50.0000']);
    PurchasingSeed::connection()->table('tax_groups')->where('id', $group)->update(['is_active' => false, 'is_inclusive' => true]);
    PurchasingSeed::connection()->table('products')->where('id', $product)->update(['name' => 'Renamed', 'is_active' => false]);
    PurchasingSeed::connection()->table('suppliers')->where('id', $desk['supplier'])->update(['name' => 'Renamed supplier', 'tax_no' => 'NEW', 'payment_terms_days' => 99]);

    $tables = PurchasingRefusals::state();

    $after = null;
    $log = PurchasingQueryLog::during(function () use (&$after, $order, $desk): void {
        $after = puGetOrder($order, $desk['buyer']);
    });

    expect($after)->toEqual($before)
        ->and($after->lines[0])->toMatchArray(['unit_factor' => '12.000000', 'base_quantity' => '12.0000', 'tax_amount' => '1.0000', 'line_total' => '11.0000'])
        ->and(PurchasingRefusals::state())->toBe($tables)
        ->and(array_values(array_filter($log, static fn (string $sql): bool => ! str_starts_with($sql, 'select'))))->toBe([]);
});

it('AC-83: an order of a soft-deleted supplier is returned with its snapshotted supplier name, and the read never touches the suppliers table', function () {
    $desk = PurchasingWorld::desk();
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['supplier_name' => 'Snapshotted Co']);

    PurchasingSeed::connection()->table('suppliers')->where('id', $desk['supplier'])->update(['deleted_at' => PurchasingSeed::NOW]);

    $result = null;
    $log = PurchasingQueryLog::during(function () use (&$result, $order, $desk): void {
        $result = puGetOrder($order, $desk['buyer']);
    });

    expect($result->order['supplier_name'])->toBe('Snapshotted Co')
        ->and($result->order['supplier_id'])->toBe($desk['supplier'])
        ->and(array_values(array_filter($log, static fn (string $sql): bool => str_contains($sql, '"suppliers"'))))->toBe([]);
});

it('AC-81 and AC-85: the view key is asked at the order s stored branch: a company-wide grant and a grant at that branch read, anything else is refused with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location']);

    $companyWide = AccessSeed::actorHolding('director', [PurchasingPermissions::ORDERS_VIEW]);
    $atBranch = AccessSeed::actorHolding('at_branch', [PurchasingPermissions::ORDERS_VIEW], $desk['branch']);
    $elsewhere = AccessSeed::actorHolding('elsewhere', [PurchasingPermissions::ORDERS_VIEW], PurchasingSeed::branch());
    $managerOnly = AccessSeed::actorHolding('manager_only', [PurchasingPermissions::ORDERS_MANAGE], $desk['branch']);
    $requestKey = AccessSeed::actorHolding('requests', [PurchasingPermissions::REQUESTS_VIEW], $desk['branch']);

    expect(puGetOrder($order, $companyWide)->order['id'])->toBe($order)
        ->and(puGetOrder($order, $atBranch)->order['id'])->toBe($order);

    foreach (['elsewhere' => $elsewhere, 'manager only' => $managerOnly, 'a request key' => $requestKey] as $who => $actor) {
        $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puGetOrder($order, $actor), $who);

        expect($refused->cause)->toBe(RefusalReason::NotPermitted);
    }
});

it('AC-81 and AC-86: a missing actor is validation and an unavailable employee is refused with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location']);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puGetOrder($order, null));

    AccessSeed::connection()->table('employees')->where('id', $desk['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puGetOrder($order, $desk['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('AC-81: an unknown id is not_found and a malformed one is validation', function () {
    $desk = PurchasingWorld::desk();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puGetOrder((string) Str::ulid(), $desk['buyer']));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puGetOrder('nope', $desk['buyer']));
});

it('AC-81: an order is readable in every status, a closed or cancelled one included', function (string $status) {
    $desk = PurchasingWorld::desk();
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['status' => $status]);

    expect(puGetOrder($order, $desk['buyer'])->order['status'])->toBe($status);
})->with(array_combine(
    array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases()),
    array_map(static fn (OrderStatus $status): array => [$status->value], OrderStatus::cases()),
));

<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\ListPurchaseOrders;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\ListPurchaseOrders\ListPurchaseOrders;
use Flexipos\Purchasing\Features\ListPurchaseOrders\ListPurchaseOrdersHandler;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Page;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 18 - ListPurchaseOrders (AC-82, AC-84, AC-86, D-34, D-36)
|--------------------------------------------------------------------------
|
| Only the branches the caller may view; newest first; keyset by before_id; an
| oversize page clamped to 100; bad paging, a bad filter or an inverted date range
| is validation.
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

function puListOrders(?string $actorId, mixed $status = null, mixed $supplierId = null, mixed $branchId = null, mixed $number = null, mixed $from = null, mixed $to = null, mixed $pageSize = null, mixed $beforeId = null): Page
{
    /** @var ListPurchaseOrdersHandler $handler */
    $handler = app(ListPurchaseOrdersHandler::class);

    return $handler->handle(new ListPurchaseOrders($actorId, $status, $supplierId, $branchId, $number, $from, $to, $pageSize, $beforeId));
}

/**
 * An id that sorts as the number it is built from, so "newest first" is exact.
 */
function puOrderListId(int $n): string
{
    return '01JZ'.str_pad(strtoupper(dechex($n)), 22, '0', STR_PAD_LEFT);
}

/**
 * `$count` orders of one desk with ids `puOrderListId(1..$count)`, in one statement.
 *
 * @param  array<string, string>  $desk
 */
function puSeedOrderList(array $desk, int $count): void
{
    $rows = [];

    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'id' => puOrderListId($i),
            'created_at' => PurchasingSeed::NOW,
            'updated_at' => PurchasingSeed::NOW,
            'number' => 'POL'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'supplier_id' => $desk['supplier'],
            'supplier_name' => 'Supplier',
            'payment_terms_days' => 0,
            'branch_id' => $desk['branch'],
            'location_id' => $desk['location'],
            'order_date' => PurchasingSeed::DATE,
            'status' => 'draft',
            'currency' => 'LKR',
            'subtotal' => '0.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'shipping' => '0.0000',
            'total' => '0.0000',
        ];
    }

    PurchasingSeed::connection()->table('purchase_orders')->insert($rows);
}

/**
 * @return list<string>
 */
function puListedOrderNumbers(Page $page): array
{
    return array_map(static fn (array $row): string => (string) $row['number'], $page->rows);
}

it('AC-82: lists newest first, 25 a page by default, and walks before_id to the end', function () {
    $desk = PurchasingWorld::desk();
    puSeedOrderList($desk, 60);

    $first = puListOrders($desk['buyer']);
    $second = puListOrders($desk['buyer'], beforeId: $first->nextCursor);
    $third = puListOrders($desk['buyer'], beforeId: $second->nextCursor);

    expect($first->rows)->toHaveCount(25)
        ->and($first->rows[0]['id'])->toBe(puOrderListId(60))
        ->and($first->nextCursor)->toBe(puOrderListId(36))
        ->and($second->rows[0]['id'])->toBe(puOrderListId(35))
        ->and($second->rows)->toHaveCount(25)
        ->and($third->rows)->toHaveCount(10)
        ->and($third->nextCursor)->toBeNull()
        ->and($third->rows[9]['id'])->toBe(puOrderListId(1))
        ->and($first->rows[0]['exchange_rate'])->toBe('1.00000000')
        ->and($first->rows[0]['total'])->toBe('0.0000');
});

it('AC-82: a page_size of 101 is clamped to 100, never refused, and a cursor past the end is an empty page', function () {
    $desk = PurchasingWorld::desk();
    puSeedOrderList($desk, 105);

    $page = puListOrders($desk['buyer'], pageSize: 101);

    expect($page->rows)->toHaveCount(100)
        ->and($page->nextCursor)->toBe(puOrderListId(6))
        ->and(puListOrders($desk['buyer'], pageSize: 1)->rows)->toHaveCount(1)
        ->and(puListOrders($desk['buyer'], beforeId: puOrderListId(1))->rows)->toBe([]);
});

it('AC-82 and AC-91: a non-positive or non-integer page_size, a malformed cursor, an unknown status and a bad supplier, number, branch or date filter are validation, even for a caller who may see nothing', function (string $field, mixed $value) {
    $desk = PurchasingWorld::desk();
    $nobody = AccessSeed::actorHolding('nobody', []);

    foreach ([$desk['buyer'], $nobody] as $actor) {
        PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => match ($field) {
            'page_size' => puListOrders($actor, pageSize: $value),
            'before_id' => puListOrders($actor, beforeId: $value),
            'status' => puListOrders($actor, status: $value),
            'supplier_id' => puListOrders($actor, supplierId: $value),
            'number' => puListOrders($actor, number: $value),
            'from' => puListOrders($actor, from: $value),
            'to' => puListOrders($actor, to: $value),
            default => puListOrders($actor, branchId: $value),
        }, $field);
    }
})->with([
    'page_size 0' => ['page_size', 0],
    'page_size -1' => ['page_size', -1],
    'page_size "x"' => ['page_size', 'x'],
    'page_size 2.5' => ['page_size', 2.5],
    'before_id malformed' => ['before_id', 'nope'],
    'before_id an int' => ['before_id', 5],
    'status unknown' => ['status', 'shipped'],
    'status in the wrong case' => ['status', 'Draft'],
    'status an array' => ['status', ['draft']],
    'supplier malformed' => ['supplier_id', 'nope'],
    'supplier an int' => ['supplier_id', 5],
    'number empty' => ['number', ''],
    'number over 30' => ['number', str_repeat('P', 31)],
    'number an int' => ['number', 5],
    'branch malformed' => ['branch_id', 'nope'],
    'from not a date' => ['from', '16/11/2026'],
    'from impossible' => ['from', '2026-02-30'],
    'from an int' => ['from', 20261116],
    'to with a time' => ['to', '2026-11-16 10:00:00'],
]);

it('AC-82: a range whose end precedes its start is validation, a one-day range and an open end are accepted', function () {
    $desk = PurchasingWorld::desk();

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puListOrders($desk['buyer'], from: '2026-11-16', to: '2026-11-15'));

    expect(puListOrders($desk['buyer'], from: '2026-11-16', to: '2026-11-16')->rows)->toBe([])
        ->and(puListOrders($desk['buyer'], from: '2026-11-16')->rows)->toBe([])
        ->and(puListOrders($desk['buyer'], to: '2026-11-16')->rows)->toBe([]);
});

it('AC-82: the status, supplier, branch, number and date filters match and combine, and no match is an empty page', function () {
    $desk = PurchasingWorld::desk();
    $other = PurchasingWorld::desk('o');
    $second = PurchasingSeed::supplier('TWO');

    PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['status' => 'draft', 'number' => 'PO000001', 'order_date' => '2026-11-10']);
    PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['status' => 'approved', 'number' => 'PO000002', 'order_date' => '2026-11-15']);
    PurchasingSeed::order($second, $desk['branch'], $desk['location'], ['status' => 'approved', 'number' => 'PO000003', 'order_date' => '2026-11-20']);
    PurchasingSeed::order($other['supplier'], $other['branch'], $other['location'], ['status' => 'approved', 'number' => 'PO000004', 'order_date' => '2026-11-25']);

    $director = AccessSeed::actorHolding('director', [PurchasingPermissions::ORDERS_VIEW]);

    expect(puListedOrderNumbers(puListOrders($director, status: 'approved')))->toBe(['PO000004', 'PO000003', 'PO000002'])
        ->and(puListedOrderNumbers(puListOrders($director, supplierId: strtolower($second))))->toBe(['PO000003'])
        ->and(puListedOrderNumbers(puListOrders($director, branchId: $desk['branch'])))->toBe(['PO000003', 'PO000002', 'PO000001'])
        ->and(puListedOrderNumbers(puListOrders($director, status: 'approved', branchId: $desk['branch'])))->toBe(['PO000003', 'PO000002'])
        ->and(puListedOrderNumbers(puListOrders($director, number: 'PO000002')))->toBe(['PO000002'])
        ->and(puListedOrderNumbers(puListOrders($director, status: 'draft', number: 'PO000002')))->toBe([])
        ->and(puListedOrderNumbers(puListOrders($director, from: '2026-11-15', to: '2026-11-20')))->toBe(['PO000003', 'PO000002'])
        ->and(puListedOrderNumbers(puListOrders($director, from: '2026-11-20')))->toBe(['PO000004', 'PO000003'])
        ->and(puListedOrderNumbers(puListOrders($director, to: '2026-11-10')))->toBe(['PO000001'])
        ->and(puListedOrderNumbers(puListOrders($director, status: 'approved', supplierId: $desk['supplier'], from: '2026-11-01', to: '2026-11-30')))->toBe(['PO000002'])
        ->and(puListOrders($director, status: 'cancelled')->rows)->toBe([])
        ->and(puListOrders($director, supplierId: (string) Str::ulid())->rows)->toBe([]);
});

it('AC-82: every status the enum names is accepted as a filter', function () {
    $desk = PurchasingWorld::desk();

    foreach (OrderStatus::cases() as $status) {
        expect(puListOrders($desk['buyer'], status: $status->value)->rows)->toBe([]);
    }
});

it('AC-82: without a branch filter only the branches where the caller holds the view key appear; a company-wide grant sees every branch', function () {
    $a = PurchasingWorld::desk('a');
    $b = PurchasingWorld::desk('b');
    PurchasingSeed::order($a['supplier'], $a['branch'], $a['location'], ['number' => 'POA']);
    PurchasingSeed::order($b['supplier'], $b['branch'], $b['location'], ['number' => 'POB']);

    $atA = AccessSeed::actorHolding('at_a', [PurchasingPermissions::ORDERS_VIEW], $a['branch']);
    $atBoth = AccessSeed::actorHolding('at_both', [PurchasingPermissions::ORDERS_VIEW], $a['branch']);
    AccessSeed::assign($atBoth, AccessSeed::role('also_b', [PurchasingPermissions::ORDERS_VIEW]), $b['branch']);
    $companyWide = AccessSeed::actorHolding('director', [PurchasingPermissions::ORDERS_VIEW]);
    $managerOnly = AccessSeed::actorHolding('manager_only', [PurchasingPermissions::ORDERS_MANAGE], $a['branch']);
    $requestKey = AccessSeed::actorHolding('request_key', [PurchasingPermissions::REQUESTS_VIEW], $a['branch']);

    expect(puListedOrderNumbers(puListOrders($atA)))->toBe(['POA'])
        ->and(puListedOrderNumbers(puListOrders($atBoth)))->toBe(['POB', 'POA'])
        ->and(puListedOrderNumbers(puListOrders($companyWide)))->toBe(['POB', 'POA'])
        ->and(puListOrders($managerOnly)->rows)->toBe([])
        ->and(puListOrders($requestKey)->rows)->toBe([]);
});

it('AC-82: none allowed is an empty page, not an error', function () {
    $desk = PurchasingWorld::desk();
    puSeedOrderList($desk, 3);
    $nobody = AccessSeed::actorHolding('nobody', []);

    $page = puListOrders($nobody);

    expect($page->rows)->toBe([])
        ->and($page->nextCursor)->toBeNull();
});

it('AC-82: with a branch filter the permission is decided once, at that branch, and a refusal is not_authorised carrying the resolver s reason', function () {
    $a = PurchasingWorld::desk('a');
    $b = PurchasingWorld::desk('b');
    PurchasingSeed::order($a['supplier'], $a['branch'], $a['location'], ['number' => 'POA']);
    PurchasingSeed::order($b['supplier'], $b['branch'], $b['location'], ['number' => 'POB']);
    $atA = AccessSeed::actorHolding('at_a', [PurchasingPermissions::ORDERS_VIEW], $a['branch']);

    expect(puListedOrderNumbers(puListOrders($atA, branchId: $a['branch'])))->toBe(['POA']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puListOrders($atA, branchId: $b['branch']));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted);
});

it('AC-82: a company-wide holder still sees the orders of a branch that has since been made inactive or soft-deleted, and of a soft-deleted supplier', function () {
    $desk = PurchasingWorld::desk();
    PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['number' => 'POOLD']);
    $director = AccessSeed::actorHolding('director', [PurchasingPermissions::ORDERS_VIEW]);

    PurchasingSeed::connection()->table('branches')->where('id', $desk['branch'])->update(['is_active' => false, 'deleted_at' => PurchasingSeed::NOW]);
    PurchasingSeed::connection()->table('suppliers')->where('id', $desk['supplier'])->update(['deleted_at' => PurchasingSeed::NOW]);

    expect(puListedOrderNumbers(puListOrders($director)))->toBe(['POOLD']);
});

it('AC-86: an employee who cannot act is refused with the resolver s reason, not given an empty page, and a missing actor is validation', function () {
    $desk = PurchasingWorld::desk();
    AccessSeed::connection()->table('employees')->where('id', $desk['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puListOrders($desk['buyer']));

    expect($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);

    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puListOrders(null));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puListOrders(null, branchId: $desk['branch']));
});

it('AC-82: an unknown employee is refused, not given an empty page', function () {
    PurchasingWorld::desk();

    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puListOrders((string) Str::ulid()));
});

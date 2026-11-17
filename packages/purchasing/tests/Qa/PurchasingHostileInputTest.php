<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Qa;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Features\ActivateSupplier\ActivateSupplier;
use Flexipos\Purchasing\Features\ActivateSupplier\ActivateSupplierHandler;
use Flexipos\Purchasing\Features\AddPurchaseOrderLine\AddPurchaseOrderLine;
use Flexipos\Purchasing\Features\AddPurchaseOrderLine\AddPurchaseOrderLineHandler;
use Flexipos\Purchasing\Features\AddPurchaseRequestLine\AddPurchaseRequestLine;
use Flexipos\Purchasing\Features\AddPurchaseRequestLine\AddPurchaseRequestLineHandler;
use Flexipos\Purchasing\Features\ApplyReceivedQuantity\ApplyReceivedQuantity;
use Flexipos\Purchasing\Features\ApplyReceivedQuantity\ApplyReceivedQuantityHandler;
use Flexipos\Purchasing\Features\CancelPurchaseOrder\CancelPurchaseOrder;
use Flexipos\Purchasing\Features\CancelPurchaseOrder\CancelPurchaseOrderHandler;
use Flexipos\Purchasing\Features\CancelPurchaseRequest\CancelPurchaseRequest;
use Flexipos\Purchasing\Features\CancelPurchaseRequest\CancelPurchaseRequestHandler;
use Flexipos\Purchasing\Features\ClosePurchaseOrder\ClosePurchaseOrder;
use Flexipos\Purchasing\Features\ClosePurchaseOrder\ClosePurchaseOrderHandler;
use Flexipos\Purchasing\Features\ConvertRequestToOrder\ConvertRequestToOrder;
use Flexipos\Purchasing\Features\ConvertRequestToOrder\ConvertRequestToOrderHandler;
use Flexipos\Purchasing\Features\CreatePurchaseOrder\CreatePurchaseOrder;
use Flexipos\Purchasing\Features\CreatePurchaseOrder\CreatePurchaseOrderHandler;
use Flexipos\Purchasing\Features\CreatePurchaseRequest\CreatePurchaseRequest;
use Flexipos\Purchasing\Features\CreatePurchaseRequest\CreatePurchaseRequestHandler;
use Flexipos\Purchasing\Features\CreateSupplier\CreateSupplier;
use Flexipos\Purchasing\Features\CreateSupplier\CreateSupplierHandler;
use Flexipos\Purchasing\Features\DeactivateSupplier\DeactivateSupplier;
use Flexipos\Purchasing\Features\DeactivateSupplier\DeactivateSupplierHandler;
use Flexipos\Purchasing\Features\DeleteSupplier\DeleteSupplier;
use Flexipos\Purchasing\Features\DeleteSupplier\DeleteSupplierHandler;
use Flexipos\Purchasing\Features\GetPurchaseOrder\GetPurchaseOrder;
use Flexipos\Purchasing\Features\GetPurchaseOrder\GetPurchaseOrderHandler;
use Flexipos\Purchasing\Features\GetPurchaseRequest\GetPurchaseRequest;
use Flexipos\Purchasing\Features\GetPurchaseRequest\GetPurchaseRequestHandler;
use Flexipos\Purchasing\Features\GetSupplier\GetSupplier;
use Flexipos\Purchasing\Features\GetSupplier\GetSupplierHandler;
use Flexipos\Purchasing\Features\ListPurchaseOrders\ListPurchaseOrders;
use Flexipos\Purchasing\Features\ListPurchaseOrders\ListPurchaseOrdersHandler;
use Flexipos\Purchasing\Features\ListPurchaseRequests\ListPurchaseRequests;
use Flexipos\Purchasing\Features\ListPurchaseRequests\ListPurchaseRequestsHandler;
use Flexipos\Purchasing\Features\ListSuppliers\ListSuppliers;
use Flexipos\Purchasing\Features\ListSuppliers\ListSuppliersHandler;
use Flexipos\Purchasing\Features\RemovePurchaseOrderLine\RemovePurchaseOrderLine;
use Flexipos\Purchasing\Features\RemovePurchaseOrderLine\RemovePurchaseOrderLineHandler;
use Flexipos\Purchasing\Features\RemovePurchaseRequestLine\RemovePurchaseRequestLine;
use Flexipos\Purchasing\Features\RemovePurchaseRequestLine\RemovePurchaseRequestLineHandler;
use Flexipos\Purchasing\Features\ReopenPurchaseOrder\ReopenPurchaseOrder;
use Flexipos\Purchasing\Features\ReopenPurchaseOrder\ReopenPurchaseOrderHandler;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrder;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrderHandler;
use Flexipos\Purchasing\Features\SubmitPurchaseRequest\SubmitPurchaseRequest;
use Flexipos\Purchasing\Features\SubmitPurchaseRequest\SubmitPurchaseRequestHandler;
use Flexipos\Purchasing\Features\SyncOrderApproval\SyncOrderApproval;
use Flexipos\Purchasing\Features\SyncOrderApproval\SyncOrderApprovalHandler;
use Flexipos\Purchasing\Features\SyncRequestApproval\SyncRequestApproval;
use Flexipos\Purchasing\Features\SyncRequestApproval\SyncRequestApprovalHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseOrder\UpdatePurchaseOrder;
use Flexipos\Purchasing\Features\UpdatePurchaseOrder\UpdatePurchaseOrderHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseOrderLine\UpdatePurchaseOrderLine;
use Flexipos\Purchasing\Features\UpdatePurchaseOrderLine\UpdatePurchaseOrderLineHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseRequest\UpdatePurchaseRequest;
use Flexipos\Purchasing\Features\UpdatePurchaseRequest\UpdatePurchaseRequestHandler;
use Flexipos\Purchasing\Features\UpdatePurchaseRequestLine\UpdatePurchaseRequestLine;
use Flexipos\Purchasing\Features\UpdatePurchaseRequestLine\UpdatePurchaseRequestLineHandler;
use Flexipos\Purchasing\Features\UpdateSupplier\UpdateSupplier;
use Flexipos\Purchasing\Features\UpdateSupplier\UpdateSupplierHandler;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\FakeWorkflowGate;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use stdClass;
use Tests\TestCase;
use Throwable;

/*
|--------------------------------------------------------------------------
| T-034 QA - AC-91 swept over every slice: hostile input is a named refusal
|--------------------------------------------------------------------------
|
| AC-91: malformed input of any kind (wrong type, null where a value is required,
| an over-long string, an impossible number) is a named `validation` refusal, never
| an unhandled exception. The developer's tests pin the cases the developer
| thought of; this file hands every field of every slice that takes an array a
| fixed set of hostile values (one field at a time, the rest valid) and fails on
| any throwable that is not a PurchasingRefused. A success is allowed: an optional
| field may legitimately take an empty value.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/purchasing-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    puEnterTenant();
    FakeWorkflowGate::bind();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return list<mixed>
 */
function puqHostile(): array
{
    return [
        null, [], ['a' => 1], 1.5, true, false, '', ' ', "\0", "a\0b", str_repeat('Z', 5000), -1, 0, PHP_INT_MAX,
        '1e5', '0x1A', "'; DROP TABLE suppliers;--", "\u{1F642}", 'NaN', '-0', '.5', '5.', new stdClass, "\u{FF12}\u{FF15}",
        "10\n", str_repeat('9', 40), '1.00000', '-1', '99999999999999.99995', '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'not-a-ulid',
        '2026-02-30', '2026-13-01', '0000-00-00', "2026-11-16\n", 'abc', '100.01', '-0.0001',
    ];
}

/**
 * Run one call per hostile value per key, return every throwable that is not a refusal.
 *
 * @param  array<string, mixed>  $base
 * @param  callable(array<string, mixed>): mixed  $call
 * @return list<string>
 */
function puqSweep(array $base, callable $call): array
{
    $bad = [];

    $run = static function (array $fields, string $label) use ($call, &$bad): void {
        try {
            $call($fields);
            puqTally(1);
        } catch (PurchasingRefused) {
            // a named refusal is the contract
        } catch (Throwable $e) {
            $bad[] = $label.' -> '.$e::class.': '.mb_substr($e->getMessage(), 0, 160);
        }
    };

    foreach (array_keys($base) as $key) {
        $without = $base;
        unset($without[$key]);
        $run($without, "{$key} absent");

        foreach (puqHostile() as $index => $value) {
            $run(array_merge($base, [$key => $value]), "{$key} = hostile#{$index} (".get_debug_type($value).')');
        }
    }

    return $bad;
}

/**
 * The number of calls that succeeded, so a sweep that refuses everything at the
 * first check (and therefore proves nothing) is visible.
 */
function puqTally(int $add = 0): int
{
    static $count = 0;

    $count += $add;

    return $count;
}

/**
 * An actor holding every purchasing key company-wide.
 */
function puqAll(): string
{
    return puActor('qaall', [
        PurchasingPermissions::SUPPLIERS_VIEW, PurchasingPermissions::SUPPLIERS_MANAGE,
        PurchasingPermissions::REQUESTS_VIEW, PurchasingPermissions::REQUESTS_MANAGE,
        PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::ORDERS_MANAGE,
        PurchasingPermissions::ORDERS_CLOSE, PurchasingPermissions::ORDERS_REOPEN,
    ]);
}

it('AC-91: no supplier field takes a hostile value into an unhandled exception', function () {
    $actor = puqAll();
    $create = fn (array $f) => app(CreateSupplierHandler::class)->handle(new CreateSupplier($f, $actor));
    $baseCreate = ['code' => 'FZ-1', 'name' => 'Fuzz', 'legal_name' => 'L', 'contact_name' => 'C', 'phone' => '1', 'email' => 'a@b.co', 'address' => ['a' => 'b'], 'tax_no' => 'T1', 'currency' => 'USD', 'payment_terms_days' => 30, 'credit_limit' => '10', 'bank_details' => ['iban' => 'X'], 'notes' => 'n'];

    $bad = puqSweep($baseCreate, function (array $f) use ($create): void {
        // a fresh code each time so a success does not turn the next call into a duplicate
        if (isset($f['code']) && $f['code'] === 'FZ-1') {
            $f['code'] = 'FZ'.random_int(1000, 9999999);
        }

        $create($f);
    });

    $supplier = PurchasingSeed::supplier('FZU');
    $update = fn (array $f) => app(UpdateSupplierHandler::class)->handle(new UpdateSupplier($f, $actor));
    $baseUpdate = ['supplier_id' => $supplier, 'name' => 'Fuzz2', 'legal_name' => 'L', 'contact_name' => 'C', 'phone' => '1', 'email' => 'a@b.co', 'address' => ['a' => 'b'], 'tax_no' => 'T1', 'currency' => 'USD', 'payment_terms_days' => 30, 'credit_limit' => '10', 'bank_details' => ['iban' => 'X'], 'notes' => 'n'];

    $bad = array_merge($bad, array_map(static fn (string $b): string => 'update: '.$b, puqSweep($baseUpdate, $update)));

    expect($bad)->toBe([])
        ->and(puqTally())->toBeGreaterThan(50);
});

it('AC-91: no request or order header field takes a hostile value into an unhandled exception', function () {
    $actor = puqAll();
    $desk = PurchasingWorld::desk('q1');

    $createRequest = fn (array $f) => app(CreatePurchaseRequestHandler::class)->handle(new CreatePurchaseRequest($f, $actor));
    $bad = puqSweep(['id' => (string) Str::ulid(), 'branch_id' => $desk['branch'], 'location_id' => $desk['location'], 'required_date' => '2026-12-01', 'notes' => 'n'], function (array $f) use ($createRequest): void {
        if (($f['id'] ?? null) !== null && is_string($f['id']) && strlen($f['id']) === 26 && ctype_alnum($f['id'])) {
            $f['id'] = (string) Str::ulid();
        }

        $createRequest($f);
    });

    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $updateRequest = fn (array $f) => app(UpdatePurchaseRequestHandler::class)->handle(new UpdatePurchaseRequest($f, $actor));
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'updateRequest: '.$b, puqSweep(['request_id' => $request, 'location_id' => $desk['location'], 'required_date' => '2026-12-02', 'notes' => 'n2'], $updateRequest)));

    $createOrder = fn (array $f) => app(CreatePurchaseOrderHandler::class)->handle(new CreatePurchaseOrder($f, $actor));
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'createOrder: '.$b, puqSweep(['id' => (string) Str::ulid(), 'supplier_id' => $desk['supplier'], 'branch_id' => $desk['branch'], 'location_id' => $desk['location'], 'expected_date' => '2026-12-01', 'currency' => 'USD', 'exchange_rate' => '1.5', 'shipping' => '2', 'terms' => 't', 'notes' => 'n'], function (array $f) use ($createOrder): void {
        if (is_string($f['id'] ?? null) && strlen($f['id']) === 26 && ctype_alnum($f['id'])) {
            $f['id'] = (string) Str::ulid();
        }

        $createOrder($f);
    })));

    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']]);
    $updateOrder = fn (array $f) => app(UpdatePurchaseOrderHandler::class)->handle(new UpdatePurchaseOrder($f, $actor));
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'updateOrder: '.$b, puqSweep(['order_id' => $order, 'supplier_id' => $desk['supplier'], 'location_id' => $desk['location'], 'expected_date' => '2026-12-03', 'currency' => 'EUR', 'exchange_rate' => '2', 'shipping' => '3', 'terms' => 't2', 'notes' => 'n2'], $updateOrder)));

    expect($bad)->toBe([]);
});

it('AC-91: no request or order line field takes a hostile value into an unhandled exception', function () {
    $actor = puqAll();
    $desk = PurchasingWorld::desk('q2');
    $group = PurchasingSeed::taxGroup('QFZ');
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $requestLine = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit']);
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']]);
    $orderLine = PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], $group);

    $fresh = static function (array $f): array {
        if (is_string($f['id'] ?? null) && strlen($f['id']) === 26 && ctype_alnum($f['id'])) {
            $f['id'] = (string) Str::ulid();
        }

        return $f;
    };

    $bad = array_map(static fn (string $b): string => 'addRequestLine: '.$b, puqSweep(
        ['id' => (string) Str::ulid(), 'request_id' => $request, 'product_id' => $desk['product'], 'unit_id' => $desk['unit'], 'quantity' => '2', 'estimated_cost' => '3.5', 'supplier_id' => $desk['supplier']],
        fn (array $f) => app(AddPurchaseRequestLineHandler::class)->handle(new AddPurchaseRequestLine($fresh($f), $actor)),
    ));
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'updateRequestLine: '.$b, puqSweep(
        ['request_id' => $request, 'line_id' => $requestLine, 'quantity' => '3', 'unit_id' => $desk['unit'], 'estimated_cost' => '4', 'supplier_id' => $desk['supplier']],
        fn (array $f) => app(UpdatePurchaseRequestLineHandler::class)->handle(new UpdatePurchaseRequestLine($f, $actor)),
    )));
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'addOrderLine: '.$b, puqSweep(
        ['id' => (string) Str::ulid(), 'order_id' => $order, 'product_id' => $desk['product'], 'unit_id' => $desk['unit'], 'quantity' => '2', 'unit_cost' => '3.5', 'discount_percent' => '5', 'tax_group_id' => $group, 'description' => 'd', 'expected_date' => '2026-12-01'],
        fn (array $f) => app(AddPurchaseOrderLineHandler::class)->handle(new AddPurchaseOrderLine($fresh($f), $actor)),
    )));
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'updateOrderLine: '.$b, puqSweep(
        ['order_id' => $order, 'line_id' => $orderLine, 'quantity' => '3', 'unit_id' => $desk['unit'], 'unit_cost' => '4', 'discount_percent' => '1', 'tax_group_id' => $group, 'description' => 'dd', 'expected_date' => '2026-12-02'],
        fn (array $f) => app(UpdatePurchaseOrderLineHandler::class)->handle(new UpdatePurchaseOrderLine($f, $actor)),
    )));

    expect($bad)->toBe([]);
});

it('AC-91: no transition, removal or receipt field takes a hostile value into an unhandled exception', function () {
    $actor = puqAll();
    $desk = PurchasingWorld::desk('q3');
    $group = PurchasingSeed::taxGroup('QFZ3');

    $bad = [];

    $approved = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['status' => 'approved', 'created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']]);
    $approvedLine = PurchasingSeed::orderItem($approved, $desk['product'], $desk['unit'], $group, 1, ['quantity' => '5.0000', 'base_quantity' => '5.0000']);

    $bad = array_merge($bad, array_map(static fn (string $b): string => 'apply: '.$b, puqSweep(
        ['application_id' => (string) Str::ulid(), 'line_id' => $approvedLine, 'received_base_delta' => '1', 'received_quantity_delta' => '1'],
        function (array $f): void {
            if (is_string($f['application_id'] ?? null) && strlen($f['application_id']) === 26 && ctype_alnum($f['application_id'])) {
                $f['application_id'] = (string) Str::ulid();
            }

            app(ApplyReceivedQuantityHandler::class)->handle(new ApplyReceivedQuantity($f, null));
        },
    )));

    $reopenOrder = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['status' => 'approved', 'created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']]);
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'reopen: '.$b, puqSweep(
        ['order_id' => $reopenOrder, 'reason' => 'because'],
        fn (array $f) => app(ReopenPurchaseOrderHandler::class)->handle(new ReopenPurchaseOrder($f, $actor)),
    )));

    $cancelOrder = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['status' => 'draft', 'created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']]);
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'cancel: '.$b, puqSweep(
        ['order_id' => $cancelOrder, 'reason' => 'because'],
        fn (array $f) => app(CancelPurchaseOrderHandler::class)->handle(new CancelPurchaseOrder($f, $actor)),
    )));

    $closeOrder = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['status' => 'received', 'created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']]);
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'close: '.$b, puqSweep(
        ['order_id' => $closeOrder, 'reason' => 'because'],
        fn (array $f) => app(ClosePurchaseOrderHandler::class)->handle(new ClosePurchaseOrder($f, $actor)),
    )));

    $draft = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['created_by' => $desk['buyer'], 'updated_by' => $desk['buyer']]);
    $draftLine = PurchasingSeed::orderItem($draft, $desk['product'], $desk['unit'], $group);
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'removeOrderLine: '.$b, puqSweep(
        ['order_id' => $draft, 'line_id' => $draftLine],
        fn (array $f) => app(RemovePurchaseOrderLineHandler::class)->handle(new RemovePurchaseOrderLine($f, $actor)),
    )));

    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $requestLine = PurchasingSeed::requestItem($request, $desk['product'], $desk['unit']);
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'removeRequestLine: '.$b, puqSweep(
        ['request_id' => $request, 'line_id' => $requestLine],
        fn (array $f) => app(RemovePurchaseRequestLineHandler::class)->handle(new RemovePurchaseRequestLine($f, $actor)),
    )));

    $approvedRequest = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => 'approved']);
    PurchasingSeed::requestItem($approvedRequest, $desk['product'], $desk['unit']);
    $bad = array_merge($bad, array_map(static fn (string $b): string => 'convert: '.$b, puqSweep(
        ['request_id' => $approvedRequest, 'supplier_id' => $desk['supplier']],
        fn (array $f) => app(ConvertRequestToOrderHandler::class)->handle(new ConvertRequestToOrder($f, $actor)),
    )));

    expect($bad)->toBe([]);
});

it('AC-91: no id-only slice or list filter takes a hostile value into an unhandled exception', function () {
    $actor = puqAll();
    $bad = [];

    $handlers = [
        'getSupplier' => fn (string $id, string $who) => app(GetSupplierHandler::class)->handle(new GetSupplier($id, $who)),
        'activate' => fn (string $id, string $who) => app(ActivateSupplierHandler::class)->handle(new ActivateSupplier($id, $who)),
        'deactivate' => fn (string $id, string $who) => app(DeactivateSupplierHandler::class)->handle(new DeactivateSupplier($id, $who)),
        'delete' => fn (string $id, string $who) => app(DeleteSupplierHandler::class)->handle(new DeleteSupplier($id, $who)),
        'getRequest' => fn (string $id, string $who) => app(GetPurchaseRequestHandler::class)->handle(new GetPurchaseRequest($id, $who)),
        'submitRequest' => fn (string $id, string $who) => app(SubmitPurchaseRequestHandler::class)->handle(new SubmitPurchaseRequest($id, $who)),
        'syncRequest' => fn (string $id, string $who) => app(SyncRequestApprovalHandler::class)->handle(new SyncRequestApproval($id, $who)),
        'cancelRequest' => fn (string $id, string $who) => app(CancelPurchaseRequestHandler::class)->handle(new CancelPurchaseRequest($id, $who)),
        'getOrder' => fn (string $id, string $who) => app(GetPurchaseOrderHandler::class)->handle(new GetPurchaseOrder($id, $who)),
        'submitOrder' => fn (string $id, string $who) => app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($id, $who)),
        'syncOrder' => fn (string $id, string $who) => app(SyncOrderApprovalHandler::class)->handle(new SyncOrderApproval($id, $who)),
    ];

    $ids = ['', ' ', "\0", str_repeat('Z', 5000), 'not-a-ulid', '01HZZZZZZZZZZZZZZZZZZZZZZZ', (string) Str::ulid(), "'; DROP TABLE suppliers;--", "\u{1F642}", '%', '_'];

    foreach ($handlers as $name => $call) {
        foreach ($ids as $index => $id) {
            try {
                $call($id, $actor);
            } catch (PurchasingRefused) {
            } catch (Throwable $e) {
                $bad[] = "{$name} id#{$index} -> ".$e::class.': '.mb_substr($e->getMessage(), 0, 160);
            }
        }
    }

    foreach ($handlers as $name => $call) {
        foreach (['', 'x'] as $actorId) {
            try {
                $call((string) Str::ulid(), $actorId);
            } catch (PurchasingRefused) {
            } catch (Throwable $e) {
                $bad[] = "{$name} -> ".$e::class.': '.mb_substr($e->getMessage(), 0, 160);
            }
        }
    }

    $lists = [];

    foreach (puqHostile() as $index => $value) {
        $lists["suppliers is_active#{$index}"] = fn () => app(ListSuppliersHandler::class)->handle(new ListSuppliers($actor, isActive: $value));
        $lists["suppliers query#{$index}"] = fn () => app(ListSuppliersHandler::class)->handle(new ListSuppliers($actor, query: $value));
        $lists["suppliers page_size#{$index}"] = fn () => app(ListSuppliersHandler::class)->handle(new ListSuppliers($actor, pageSize: $value));
        $lists["suppliers after_code#{$index}"] = fn () => app(ListSuppliersHandler::class)->handle(new ListSuppliers($actor, afterCode: $value));
        $lists["requests status#{$index}"] = fn () => app(ListPurchaseRequestsHandler::class)->handle(new ListPurchaseRequests($actor, status: $value));
        $lists["requests branch#{$index}"] = fn () => app(ListPurchaseRequestsHandler::class)->handle(new ListPurchaseRequests($actor, branchId: $value));
        $lists["requests number#{$index}"] = fn () => app(ListPurchaseRequestsHandler::class)->handle(new ListPurchaseRequests($actor, number: $value));
        $lists["requests page_size#{$index}"] = fn () => app(ListPurchaseRequestsHandler::class)->handle(new ListPurchaseRequests($actor, pageSize: $value));
        $lists["requests before#{$index}"] = fn () => app(ListPurchaseRequestsHandler::class)->handle(new ListPurchaseRequests($actor, beforeId: $value));
        $lists["orders status#{$index}"] = fn () => app(ListPurchaseOrdersHandler::class)->handle(new ListPurchaseOrders($actor, status: $value));
        $lists["orders supplier#{$index}"] = fn () => app(ListPurchaseOrdersHandler::class)->handle(new ListPurchaseOrders($actor, supplierId: $value));
        $lists["orders branch#{$index}"] = fn () => app(ListPurchaseOrdersHandler::class)->handle(new ListPurchaseOrders($actor, branchId: $value));
        $lists["orders number#{$index}"] = fn () => app(ListPurchaseOrdersHandler::class)->handle(new ListPurchaseOrders($actor, number: $value));
        $lists["orders from#{$index}"] = fn () => app(ListPurchaseOrdersHandler::class)->handle(new ListPurchaseOrders($actor, orderDateFrom: $value));
        $lists["orders to#{$index}"] = fn () => app(ListPurchaseOrdersHandler::class)->handle(new ListPurchaseOrders($actor, orderDateTo: $value));
        $lists["orders page_size#{$index}"] = fn () => app(ListPurchaseOrdersHandler::class)->handle(new ListPurchaseOrders($actor, pageSize: $value));
        $lists["orders before#{$index}"] = fn () => app(ListPurchaseOrdersHandler::class)->handle(new ListPurchaseOrders($actor, beforeId: $value));
    }

    foreach ($lists as $label => $call) {
        try {
            $call();
        } catch (PurchasingRefused) {
        } catch (Throwable $e) {
            $bad[] = "list {$label} -> ".$e::class.': '.mb_substr($e->getMessage(), 0, 160);
        }
    }

    expect($bad)->toBe([]);
});

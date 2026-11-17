<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Qa;

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\ApplyReceivedQuantity\ApplyReceivedQuantity;
use Flexipos\Purchasing\Features\ApplyReceivedQuantity\ApplyReceivedQuantityHandler;
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
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 23 - cross-tenant rows on the stand-in (AC-88, D-46)
|--------------------------------------------------------------------------
|
| One root entity at a time (supplier, request, order, receipt application): a row
| written in tenant A is `not_found` when its id is used in tenant B, and tenant B's
| lists and tables hold nothing of A's. The two tenants are two real SQLite
| databases behind the one `tenant` connection, switched with
| `AccessTenantFixture::switchTo()`, so what is shown is that the connection is the
| boundary and that no slice carries a tenant predicate of its own.
|
| The actor in tenant B holds every purchasing key company-wide, so a `not_found`
| here is never a permission answer: the row simply is not in B's database. The
| application root has no read slice, so its id is proved through the one slice
| that takes it, `ApplyReceivedQuantity`, by the line it names; the application
| table of B is read whole.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/purchasing-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    puEnterTenant('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Tenant A holds one of every root entity. The ids are returned; the actor of A is not needed again.
 *
 * @return array{supplier: string, request: string, order: string, line: string, application: string}
 */
function puCrossTenantRows(): array
{
    $desk = PurchasingWorld::desk('xa');
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], [
        'status' => 'approved',
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
    ]);
    $line = PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], PurchasingSeed::taxGroup('XTA'));
    $application = PurchasingSeed::receiptApplication($order, $line, '1.0000', '1.0000');

    return [
        'supplier' => $desk['supplier'],
        'request' => PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']),
        'order' => $order,
        'line' => $line,
        'application' => $application,
    ];
}

/**
 * Leave tenant A, enter tenant B, and return an actor there holding every purchasing key company-wide.
 */
function puCrossTenantEnterB(): string
{
    AccessTenantFixture::switchTo('tenant-b');

    /** @var SyncPermissionRegistryHandler $sync */
    $sync = app(SyncPermissionRegistryHandler::class);
    $sync->handle(new SyncPermissionRegistry);

    return puActor('xb', [
        PurchasingPermissions::SUPPLIERS_VIEW,
        PurchasingPermissions::SUPPLIERS_MANAGE,
        PurchasingPermissions::REQUESTS_VIEW,
        PurchasingPermissions::REQUESTS_MANAGE,
        PurchasingPermissions::ORDERS_VIEW,
        PurchasingPermissions::ORDERS_MANAGE,
    ]);
}

it('AC-88: a supplier of tenant A is not_found in tenant B, unchanged in A, and absent from B\'s list', function () {
    $a = puCrossTenantRows();
    $before = PurchasingSeed::row('suppliers', $a['supplier']);

    $actor = puCrossTenantEnterB();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => app(GetSupplierHandler::class)->handle(new GetSupplier($a['supplier'], $actor)), 'get');
    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => app(DeleteSupplierHandler::class)->handle(new DeleteSupplier($a['supplier'], $actor)), 'delete');

    $listed = app(ListSuppliersHandler::class)->handle(new ListSuppliers($actor));

    expect($listed->rows)->toBe([])
        ->and(PurchasingSeed::connection()->table('suppliers')->count())->toBe(0)
        ->and(PurchasingSeed::connection()->table('audit_logs')->where('action', 'like', 'purchasing_%')->count())->toBe(0);

    AccessTenantFixture::switchTo('tenant-a');

    expect(PurchasingSeed::row('suppliers', $a['supplier']))->toBe($before);
});

it('AC-88: a purchase request of tenant A is not_found in tenant B and absent from B\'s list', function () {
    $a = puCrossTenantRows();

    $actor = puCrossTenantEnterB();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => app(GetPurchaseRequestHandler::class)->handle(new GetPurchaseRequest($a['request'], $actor)));

    $listed = app(ListPurchaseRequestsHandler::class)->handle(new ListPurchaseRequests($actor));

    expect($listed->rows)->toBe([])
        ->and(PurchasingSeed::connection()->table('purchase_requests')->count())->toBe(0)
        ->and(PurchasingSeed::connection()->table('purchase_request_items')->count())->toBe(0);
});

it('AC-88: a purchase order and its lines of tenant A are not_found in tenant B and absent from B\'s list', function () {
    $a = puCrossTenantRows();

    $actor = puCrossTenantEnterB();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => app(GetPurchaseOrderHandler::class)->handle(new GetPurchaseOrder($a['order'], $actor)));

    $listed = app(ListPurchaseOrdersHandler::class)->handle(new ListPurchaseOrders($actor));

    expect($listed->rows)->toBe([])
        ->and(PurchasingSeed::connection()->table('purchase_orders')->count())->toBe(0)
        ->and(PurchasingSeed::connection()->table('purchase_order_items')->count())->toBe(0);
});

it('AC-88: a receipt of tenant A cannot be applied through tenant B, writes nothing there, and A\'s application stays alone', function () {
    $a = puCrossTenantRows();

    puCrossTenantEnterB();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => app(ApplyReceivedQuantityHandler::class)->handle(new ApplyReceivedQuantity([
        'application_id' => (string) Str::ulid(),
        'line_id' => $a['line'],
        'received_base_delta' => '1.0000',
        'received_quantity_delta' => '1.0000',
    ], null)));

    expect(PurchasingSeed::connection()->table('purchase_order_receipt_applications')->count())->toBe(0)
        ->and(PurchasingSeed::connection()->table('audit_logs')->where('action', 'like', 'purchasing_%')->count())->toBe(0)
        ->and(PurchasingSeed::connection()->table('outbox_events')->count())->toBe(0);

    AccessTenantFixture::switchTo('tenant-a');

    expect(PurchasingSeed::connection()->table('purchase_order_receipt_applications')->pluck('id')->all())->toBe([$a['application']])
        ->and(PurchasingSeed::row('purchase_order_items', $a['line'])['received_base_quantity'])->toEqual(0);
});

it('AC-88 control: the same ids are found in tenant A, so the not_found above is the tenant boundary and not a bad id', function () {
    $a = puCrossTenantRows();
    $desk = PurchasingSeed::connection()->table('purchase_orders')->where('id', $a['order'])->first();
    $actor = puActor('xa-viewer', [PurchasingPermissions::SUPPLIERS_VIEW, PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::REQUESTS_VIEW]);

    expect($desk)->not->toBeNull()
        ->and(app(GetSupplierHandler::class)->handle(new GetSupplier($a['supplier'], $actor))->supplier['id'])->toBe($a['supplier'])
        ->and(app(GetPurchaseRequestHandler::class)->handle(new GetPurchaseRequest($a['request'], $actor))->request['id'])->toBe($a['request'])
        ->and(app(GetPurchaseOrderHandler::class)->handle(new GetPurchaseOrder($a['order'], $actor))->order['id'])->toBe($a['order']);
});

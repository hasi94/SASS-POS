<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Invariants;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\ReceiptRollup;
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
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Money;
use Flexipos\Purchasing\Tests\Support\PurchasingOutbox;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 20 - the cross-slice invariant sweep (AC-87, AC-97 and the NFRs)
|--------------------------------------------------------------------------
|
| One scripted day, run through the real slices and the real approval engine:
| a supplier made, edited, switched off and on, and a second one deleted; a
| request built up, trimmed, approved and converted; a request cancelled; an
| order taken from the conversion, edited, approved and received in two
| applications; an order over the approval threshold sent to the engine,
| approved and synced, part received and closed; an order over the threshold
| rejected back to draft, edited and cancelled; an order approved, reopened,
| approved again and cancelled. Then every table is read whole and the
| invariants are asserted over all of it, not over the rows a slice's own test
| made.
|
| What is written by hand and what is derived. The two tallies (the audit rows
| by action, the events by type) are literals worked out from the script above,
| one line of reasoning per step in the comments beside the script. Everything
| else is compared with what the day's own rows say it must be: the sequence
| counters against the document counts, the header sums against the lines, each
| line's received figures against its applications, each order's status against
| the stored sums.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/purchasing-fixture.php';

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
 * The tables the module owns and the ones a purchasing write may legitimately move (the audit trail, the outbox, the
 * number counters and the approval engine's own rows).
 *
 * @var list<string>
 */
const PIV_WRITABLE = [
    'suppliers', 'purchase_requests', 'purchase_request_items', 'purchase_orders', 'purchase_order_items',
    'purchase_order_receipt_applications', 'audit_logs', 'outbox_events', 'number_sequences',
    'workflow_instances', 'workflow_tasks', 'workflow_events',
];

/**
 * Every table of the tenant database with its row count.
 *
 * @return array<string, int>
 */
function pivCounts(): array
{
    $counts = [];

    foreach (PurchasingSeed::connection()->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name") as $table) {
        $name = (string) $table->name;
        $counts[$name] = PurchasingSeed::connection()->table($name)->count();
    }

    return $counts;
}

/**
 * Run the whole day. Returns what the sweep needs to name rows.
 *
 * @return array{boss: string, orders: array<string, string>, requests: array<string, string>, suppliers: array<string, string>, before: array<string, int>}
 */
function pivRunTheDay(): array
{
    $desk = PurchasingWorld::desk('iv');
    $group = PurchasingSeed::taxGroup('IVTX');
    $product = PurchasingSeed::product('IV-PRODUCT', $desk['unit'], ['purchase_tax_group_id' => $group]);
    $boss = puActor('ivboss', [
        PurchasingPermissions::SUPPLIERS_VIEW, PurchasingPermissions::SUPPLIERS_MANAGE,
        PurchasingPermissions::REQUESTS_VIEW, PurchasingPermissions::REQUESTS_MANAGE,
        PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::ORDERS_MANAGE,
        PurchasingPermissions::ORDERS_CLOSE, PurchasingPermissions::ORDERS_REOPEN,
    ]);
    $approver = PurchasingWorld::approver('ivapr');

    // Everything the day does not own is counted now, after the world is built and before the first slice runs.
    $before = pivCounts();

    $branch = $desk['branch'];
    $location = $desk['location'];
    $unit = $desk['unit'];

    // 1. Suppliers: S1 created, edited, switched off and on; S2 created and deleted.
    $s1 = app(CreateSupplierHandler::class)->handle(new CreateSupplier(['code' => 'iv-s1', 'name' => 'Day Supplier', 'payment_terms_days' => 30], $boss))->supplierId;
    app(UpdateSupplierHandler::class)->handle(new UpdateSupplier(['supplier_id' => $s1, 'name' => 'Day Supplier Ltd'], $boss));
    app(DeactivateSupplierHandler::class)->handle(new DeactivateSupplier($s1, $boss));
    app(ActivateSupplierHandler::class)->handle(new ActivateSupplier($s1, $boss));
    $s2 = app(CreateSupplierHandler::class)->handle(new CreateSupplier(['code' => 'iv-s2', 'name' => 'Short Lived'], $boss))->supplierId;
    app(DeleteSupplierHandler::class)->handle(new DeleteSupplier($s2, $boss));

    // 2. Request R1: two lines, one trimmed, one edited, a header edit, submitted (approved at once), converted.
    $r1 = (string) Str::ulid();
    app(CreatePurchaseRequestHandler::class)->handle(new CreatePurchaseRequest(['id' => $r1, 'branch_id' => $branch, 'location_id' => $location], $boss));
    $r1a = app(AddPurchaseRequestLineHandler::class)->handle(new AddPurchaseRequestLine(['id' => (string) Str::ulid(), 'request_id' => $r1, 'product_id' => $product, 'unit_id' => $unit, 'quantity' => '10', 'estimated_cost' => '5'], $boss))->lineId;
    $r1b = app(AddPurchaseRequestLineHandler::class)->handle(new AddPurchaseRequestLine(['id' => (string) Str::ulid(), 'request_id' => $r1, 'product_id' => $product, 'unit_id' => $unit, 'quantity' => '1', 'estimated_cost' => '1'], $boss))->lineId;
    app(UpdatePurchaseRequestLineHandler::class)->handle(new UpdatePurchaseRequestLine(['request_id' => $r1, 'line_id' => $r1b, 'quantity' => '2'], $boss));
    app(RemovePurchaseRequestLineHandler::class)->handle(new RemovePurchaseRequestLine(['request_id' => $r1, 'line_id' => $r1b], $boss));
    app(UpdatePurchaseRequestHandler::class)->handle(new UpdatePurchaseRequest(['request_id' => $r1, 'notes' => 'Weekly restock'], $boss));
    app(SubmitPurchaseRequestHandler::class)->handle(new SubmitPurchaseRequest($r1, $boss));

    // 3. Request R2: one line, cancelled.
    $r2 = (string) Str::ulid();
    app(CreatePurchaseRequestHandler::class)->handle(new CreatePurchaseRequest(['id' => $r2, 'branch_id' => $branch, 'location_id' => $location], $boss));
    app(AddPurchaseRequestLineHandler::class)->handle(new AddPurchaseRequestLine(['id' => (string) Str::ulid(), 'request_id' => $r2, 'product_id' => $product, 'unit_id' => $unit, 'quantity' => '3'], $boss));
    app(CancelPurchaseRequestHandler::class)->handle(new CancelPurchaseRequest($r2, $boss));

    // 4. Order O1 from R1: a second line added and edited, submitted (below the threshold, so approved at once),
    //    received in two applications, one per line.
    $converted = app(ConvertRequestToOrderHandler::class)->handle(new ConvertRequestToOrder(['request_id' => $r1, 'supplier_id' => $s1], $boss));
    $o1 = $converted->orderId;
    $o1a = (string) $converted->lines[0]['id'];
    $o1b = app(AddPurchaseOrderLineHandler::class)->handle(new AddPurchaseOrderLine(['id' => (string) Str::ulid(), 'order_id' => $o1, 'product_id' => $product, 'unit_id' => $unit, 'quantity' => '4', 'unit_cost' => '5', 'tax_group_id' => $group], $boss))->lineId;
    app(UpdatePurchaseOrderLineHandler::class)->handle(new UpdatePurchaseOrderLine(['order_id' => $o1, 'line_id' => $o1b, 'unit_cost' => '6'], $boss));
    app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($o1, $boss));
    app(ApplyReceivedQuantityHandler::class)->handle(new ApplyReceivedQuantity(['application_id' => (string) Str::ulid(), 'line_id' => $o1a, 'received_base_delta' => '10', 'received_quantity_delta' => '10'], $boss));
    app(ApplyReceivedQuantityHandler::class)->handle(new ApplyReceivedQuantity(['application_id' => (string) Str::ulid(), 'line_id' => $o1b, 'received_base_delta' => '4', 'received_quantity_delta' => '4'], $boss));

    // From here an order of 100 or more goes to the engine; the approver is a different employee.
    PurchasingSeed::workflowDefinition('purchase_order', '100', 'return_to_requester', $approver['role']);

    // 5. Order O2: over the threshold, pending, approved by the engine, synced, part received, closed.
    $o2 = (string) Str::ulid();
    app(CreatePurchaseOrderHandler::class)->handle(new CreatePurchaseOrder(['id' => $o2, 'supplier_id' => $s1, 'branch_id' => $branch, 'location_id' => $location], $boss));
    $o2a = app(AddPurchaseOrderLineHandler::class)->handle(new AddPurchaseOrderLine(['id' => (string) Str::ulid(), 'order_id' => $o2, 'product_id' => $product, 'unit_id' => $unit, 'quantity' => '100', 'unit_cost' => '10', 'discount_percent' => '5', 'tax_group_id' => $group], $boss))->lineId;
    $submitted = app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($o2, $boss));
    PurchasingWorld::decide((string) $submitted->workflowInstanceId, WorkflowVerdict::Approve);
    app(SyncOrderApprovalHandler::class)->handle(new SyncOrderApproval($o2, $boss));
    app(ApplyReceivedQuantityHandler::class)->handle(new ApplyReceivedQuantity(['application_id' => (string) Str::ulid(), 'line_id' => $o2a, 'received_base_delta' => '40', 'received_quantity_delta' => '40'], $boss));
    app(ClosePurchaseOrderHandler::class)->handle(new ClosePurchaseOrder(['order_id' => $o2, 'reason' => 'Supplier will not ship the rest'], $boss));

    // 6. Order O3: over the threshold, rejected back to draft, edited, cancelled from draft.
    $o3 = (string) Str::ulid();
    app(CreatePurchaseOrderHandler::class)->handle(new CreatePurchaseOrder(['id' => $o3, 'supplier_id' => $s1, 'branch_id' => $branch, 'location_id' => $location], $boss));
    app(AddPurchaseOrderLineHandler::class)->handle(new AddPurchaseOrderLine(['id' => (string) Str::ulid(), 'order_id' => $o3, 'product_id' => $product, 'unit_id' => $unit, 'quantity' => '50', 'unit_cost' => '10', 'tax_group_id' => $group], $boss));
    $submitted = app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($o3, $boss));
    PurchasingWorld::decide((string) $submitted->workflowInstanceId, WorkflowVerdict::Reject);
    app(SyncOrderApprovalHandler::class)->handle(new SyncOrderApproval($o3, $boss));
    app(UpdatePurchaseOrderHandler::class)->handle(new UpdatePurchaseOrder(['order_id' => $o3, 'notes' => 'Rejected, to be redone'], $boss));
    app(CancelPurchaseOrderHandler::class)->handle(new CancelPurchaseOrder(['order_id' => $o3, 'reason' => 'Not needed'], $boss));

    // 7. Order O4: below the threshold, approved at once, reopened, approved again, cancelled from approved.
    $o4 = (string) Str::ulid();
    app(CreatePurchaseOrderHandler::class)->handle(new CreatePurchaseOrder(['id' => $o4, 'supplier_id' => $s1, 'branch_id' => $branch, 'location_id' => $location], $boss));
    app(AddPurchaseOrderLineHandler::class)->handle(new AddPurchaseOrderLine(['id' => (string) Str::ulid(), 'order_id' => $o4, 'product_id' => $product, 'unit_id' => $unit, 'quantity' => '2', 'unit_cost' => '10', 'tax_group_id' => $group], $boss));
    app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($o4, $boss));
    app(ReopenPurchaseOrderHandler::class)->handle(new ReopenPurchaseOrder(['order_id' => $o4, 'reason' => 'Wrong quantity'], $boss));
    app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($o4, $boss));
    app(CancelPurchaseOrderHandler::class)->handle(new CancelPurchaseOrder(['order_id' => $o4, 'reason' => 'Superseded'], $boss));

    return [
        'boss' => $boss,
        'orders' => ['o1' => $o1, 'o2' => $o2, 'o3' => $o3, 'o4' => $o4],
        'requests' => ['r1' => $r1, 'r2' => $r2],
        'suppliers' => ['s1' => $s1, 's2' => $s2],
        'before' => $before,
    ];
}

/**
 * @return array<string, int>
 */
function pivAuditTally(): array
{
    $tally = [];

    foreach (PurchasingSeed::connection()->table('audit_logs')->where('module', 'purchasing')->pluck('action') as $action) {
        $tally[(string) $action] = ($tally[(string) $action] ?? 0) + 1;
    }

    ksort($tally);

    return $tally;
}

it('AC-87: the day writes exactly the audit rows the script implies, every one a declared action of this module, in the day\'s own transactions', function () {
    $day = pivRunTheDay();

    $expected = [
        // 1. suppliers: two created, one edited, off, on, one deleted.
        PurchasingAuditActions::SUPPLIER_CREATED => 2,
        PurchasingAuditActions::SUPPLIER_UPDATED => 1,
        PurchasingAuditActions::SUPPLIER_DEACTIVATED => 1,
        PurchasingAuditActions::SUPPLIER_ACTIVATED => 1,
        PurchasingAuditActions::SUPPLIER_DELETED => 1,
        // 2 and 3. requests: R1 and R2 created; R1 three lines added (R1a, R1b, R2's), one edited, one removed, header edited, approved at
        //    once, converted; R2 cancelled.
        PurchasingAuditActions::REQUEST_CREATED => 2,
        PurchasingAuditActions::REQUEST_LINE_ADDED => 3,
        PurchasingAuditActions::REQUEST_LINE_UPDATED => 1,
        PurchasingAuditActions::REQUEST_LINE_REMOVED => 1,
        PurchasingAuditActions::REQUEST_UPDATED => 1,
        PurchasingAuditActions::REQUEST_APPROVED => 1,
        PurchasingAuditActions::REQUEST_CONVERTED => 1,
        PurchasingAuditActions::REQUEST_CANCELLED => 1,
        // 4 to 7. orders: four created (O1 by the conversion); lines added to O1 (second), O2, O3, O4; O1's second line edited; two sent to
        //    the engine (O2, O3); approved: O1 at once, O2 by sync, O4 twice; O3 returned to draft and edited; O4 reopened; O3 and O4
        //    cancelled; O2 closed; three receipts.
        PurchasingAuditActions::ORDER_CREATED => 4,
        PurchasingAuditActions::ORDER_LINE_ADDED => 4,
        PurchasingAuditActions::ORDER_LINE_UPDATED => 1,
        PurchasingAuditActions::ORDER_SUBMITTED => 2,
        PurchasingAuditActions::ORDER_APPROVED => 4,
        PurchasingAuditActions::ORDER_RETURNED_TO_DRAFT => 1,
        PurchasingAuditActions::ORDER_UPDATED => 1,
        PurchasingAuditActions::ORDER_REOPENED => 1,
        PurchasingAuditActions::ORDER_CANCELLED => 2,
        PurchasingAuditActions::ORDER_CLOSED => 1,
        PurchasingAuditActions::ORDER_RECEIPT_APPLIED => 3,
    ];

    ksort($expected);

    expect(pivAuditTally())->toBe($expected);

    // Every action is one this module declares, no declared action is spelt another way, and each row names a subject that exists.
    $subjects = [
        'supplier' => 'suppliers', 'purchase_request' => 'purchase_requests', 'purchase_order' => 'purchase_orders',
    ];

    foreach (PurchasingSeed::audit() as $row) {
        expect(PurchasingAuditActions::ALL)->toContain($row['action'])
            ->and($row['module'])->toBe(PurchasingAuditActions::MODULE)
            ->and($row['actor_id'])->not->toBeNull();

        $table = $subjects[(string) $row['subject_type']] ?? null;

        if ($table !== null) {
            expect(PurchasingSeed::connection()->table($table)->where('id', $row['subject_id'])->exists())->toBeTrue("Audit row [{$row['action']}] names a missing {$row['subject_type']}.");
        }
    }

    expect(array_keys($day['orders']))->toBe(['o1', 'o2', 'o3', 'o4']);
});

it('AC-97: the day writes exactly the lifecycle events the script implies, ids and base figures only, each for an order that exists', function () {
    $day = pivRunTheDay();
    $types = PurchasingOutbox::types();
    $count = array_count_values($types);

    ksort($count);

    $expected = [
        // O1, O2, O4 twice.
        'purchase_order.approved' => 4,
        // O2.
        'purchase_order.closed' => 1,
        // O4 only: O3 was cancelled from draft and never approved.
        'purchase_order.cancelled' => 1,
        // O4.
        'purchase_order.reopened' => 1,
        // O1 twice, O2 once.
        'purchase_order.received_applied' => 3,
    ];

    ksort($expected);

    expect($count)->toBe($expected);

    $supplierName = (string) PurchasingSeed::row('suppliers', $day['suppliers']['s1'])['name'];

    foreach (PurchasingOutbox::events() as $event) {
        $payload = (array) $event['payload'];

        expect(PurchasingSeed::connection()->table('purchase_orders')->where('id', $payload['order_id'])->exists())->toBeTrue()
            ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain($supplierName)
            ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('bank_details');
    }
});

it('the day moves no table it does not own: every other table keeps its row count, and the six purchasing tables and the counters are the only ones that grow', function () {
    $day = pivRunTheDay();
    $after = pivCounts();

    foreach ($day['before'] as $table => $count) {
        // The approval definition is the day's own seed (written mid-day, once the first order is through), not a purchasing write.
        if (in_array($table, PIV_WRITABLE, true) || in_array($table, ['workflow_definitions', 'workflow_steps'], true)) {
            continue;
        }

        expect($after[$table])->toBe($count, "Table [{$table}] changed during the day.");
    }

    // The control: the writable set is what really moved, so the loop above compared something.
    foreach (['suppliers', 'purchase_requests', 'purchase_request_items', 'purchase_orders', 'purchase_order_items', 'purchase_order_receipt_applications', 'audit_logs', 'outbox_events', 'number_sequences'] as $table) {
        expect($after[$table])->toBeGreaterThan($day['before'][$table], "Table [{$table}] should have grown.");
    }

    expect(count(array_diff(array_keys($after), PIV_WRITABLE)))->toBeGreaterThan(20);
});

it('the numbers and counters: PR and PO run unbroken from one, and each sequence stands one past the documents drawn from it', function () {
    pivRunTheDay();

    $requests = PurchasingSeed::connection()->table('purchase_requests')->orderBy('number')->pluck('number')->all();
    $orders = PurchasingSeed::connection()->table('purchase_orders')->orderBy('number')->pluck('number')->all();

    expect($requests)->toBe(['PR000001', 'PR000002'])
        ->and($orders)->toBe(['PO000001', 'PO000002', 'PO000003', 'PO000004']);

    $sequences = PurchasingSeed::connection()->table('number_sequences')->orderBy('document_type')->get()
        ->mapWithKeys(static fn (object $row): array => [(string) $row->document_type => (int) $row->next_number])->all();

    expect($sequences)->toBe(['purchase_order' => 5, 'purchase_request' => 3]);
});

it('the figures: every header is the sum of its lines, every line the sum of its parts, no figure is negative and a deleted supplier keeps its rows', function () {
    $day = pivRunTheDay();

    foreach (PurchasingSeed::connection()->table('purchase_orders')->get() as $order) {
        $lines = PurchasingSeed::connection()->table('purchase_order_items')->where('order_id', $order->id)->get();

        $sum = static fn (string $column): string => Money::sum($lines->map(static fn (object $line): string => Money::of($line->{$column}))->all());

        expect(Money::equals(Money::of($order->subtotal), $sum('subtotal')))->toBeTrue("[{$order->number}] subtotal")
            ->and(Money::equals(Money::of($order->discount_total), $sum('discount_amount')))->toBeTrue("[{$order->number}] discount_total")
            ->and(Money::equals(Money::of($order->tax_total), $sum('tax_amount')))->toBeTrue("[{$order->number}] tax_total");

        $expectedTotal = Money::add(Money::add(Money::subtract(Money::of($order->subtotal), Money::of($order->discount_total)), Money::of($order->tax_total)), Money::of($order->shipping));

        expect(Money::equals(Money::of($order->total), $expectedTotal))->toBeTrue("[{$order->number}] total");

        foreach ($lines as $line) {
            $lineTotal = Money::add(Money::subtract(Money::of($line->subtotal), Money::of($line->discount_amount)), Money::of($line->tax_amount));

            expect(Money::equals(Money::of($line->line_total), $lineTotal))->toBeTrue("[{$order->number}] line {$line->line_no}");

            foreach (['quantity', 'base_quantity', 'unit_cost', 'subtotal', 'discount_amount', 'tax_amount', 'line_total', 'received_quantity', 'received_base_quantity'] as $column) {
                expect(Money::compare(Money::of($line->{$column}), '0.0000'))->toBeGreaterThanOrEqual(0, "[{$order->number}] line {$line->line_no} {$column}");
            }
        }
    }

    expect(PurchasingSeed::row('suppliers', $day['suppliers']['s2'])['deleted_at'])->not->toBeNull();
});

it('AC-69, AC-70: every line\'s received figures are the sum of its applications, and every receiving order\'s status follows the stored base sums', function () {
    $day = pivRunTheDay();

    foreach (PurchasingSeed::connection()->table('purchase_order_items')->get() as $line) {
        $applications = PurchasingSeed::connection()->table('purchase_order_receipt_applications')->where('order_item_id', $line->id)->get();

        expect(Money::equals(Money::of($line->received_base_quantity), Money::sum($applications->map(static fn (object $a): string => Money::of($a->received_base_delta))->all())))->toBeTrue()
            ->and(Money::equals(Money::of($line->received_quantity), Money::sum($applications->map(static fn (object $a): string => Money::of($a->received_quantity_delta))->all())))->toBeTrue();
    }

    foreach (PurchasingSeed::connection()->table('purchase_orders')->get() as $order) {
        if (! in_array($order->status, ['approved', 'partially_received', 'received'], true)) {
            continue;
        }

        $lines = PurchasingSeed::connection()->table('purchase_order_items')->where('order_id', $order->id)->get()->map(static fn (object $l): array => (array) $l)->all();

        expect(ReceiptRollup::status($lines)->value)->toBe($order->status, "[{$order->number}] status");
    }

    $statuses = [];

    foreach ($day['orders'] as $key => $id) {
        $statuses[$key] = PurchasingSeed::row('purchase_orders', $id)['status'];
    }

    // Worked by hand: O1 received in full, O2 closed after a part, O3 and O4 cancelled.
    expect($statuses)->toBe(['o1' => OrderStatus::Received->value, 'o2' => OrderStatus::Closed->value, 'o3' => OrderStatus::Cancelled->value, 'o4' => OrderStatus::Cancelled->value]);
});

it('AC-59: an approval instance exists for exactly the orders sent to the engine, and each order that holds one names a real instance', function () {
    $day = pivRunTheDay();

    expect(PurchasingSeed::connection()->table('workflow_instances')->count())->toBe(2);

    $ids = PurchasingSeed::connection()->table('workflow_instances')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

    foreach (PurchasingSeed::connection()->table('purchase_orders')->get() as $order) {
        if ($order->workflow_instance_id !== null) {
            expect($ids)->toContain((string) $order->workflow_instance_id);
        }
    }

    // O2 keeps its approved instance; O3's was cleared when it went back to draft; O1 and O4 never had one.
    expect(PurchasingSeed::row('purchase_orders', $day['orders']['o2'])['workflow_instance_id'])->not->toBeNull()
        ->and(PurchasingSeed::row('purchase_orders', $day['orders']['o3'])['workflow_instance_id'])->toBeNull()
        ->and(PurchasingSeed::row('purchase_orders', $day['orders']['o1'])['workflow_instance_id'])->toBeNull()
        ->and(PurchasingSeed::row('purchase_orders', $day['orders']['o4'])['workflow_instance_id'])->toBeNull();
});

it('the request side: R1 ends converted with its one line pointing at the order, R2 ends cancelled', function () {
    $day = pivRunTheDay();

    $r1 = PurchasingSeed::row('purchase_requests', $day['requests']['r1']);
    $lines = PurchasingSeed::connection()->table('purchase_request_items')->where('request_id', $day['requests']['r1'])->get();

    expect($r1['status'])->toBe('converted')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->purchase_order_item_id)->not->toBeNull()
        ->and(PurchasingSeed::row('purchase_requests', $day['requests']['r2'])['status'])->toBe('cancelled')
        ->and(PurchasingSeed::row('purchase_orders', $day['orders']['o1'])['request_id'])->toBe($day['requests']['r1']);
});

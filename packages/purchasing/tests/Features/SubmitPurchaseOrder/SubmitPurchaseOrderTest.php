<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\SubmitPurchaseOrder;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Workflow\WorkflowDecision;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowOutcome;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrder;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrderHandler;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrderResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\FakeWorkflowGate;
use Flexipos\Purchasing\Tests\Support\PurchasingOutbox;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 19a - SubmitPurchaseOrder (AC-54 to AC-58, AC-97)
|--------------------------------------------------------------------------
|
| The exact context the gate is handed, the fail-closed catch, the refusals judged
| before the gate is asked, and the two outcomes (approved at once, pending) against
| the real engine. A repeat asks nothing and writes nothing.
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

function puSubmitOrder(string $orderId, ?string $actorId): SubmitPurchaseOrderResult
{
    /** @var SubmitPurchaseOrderHandler $handler */
    $handler = app(SubmitPurchaseOrderHandler::class);

    return $handler->handle(new SubmitPurchaseOrder($orderId, $actorId));
}

/**
 * An order on the desk with one line of 11.0000, so a header total of 11.0000.
 *
 * @param  array<string, string>  $desk
 * @param  array<string, mixed>  $overrides
 */
function puSubmittableOrder(array $desk, array $overrides = [], bool $withLine = true): string
{
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], array_merge([
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'subtotal' => '10.0000',
        'tax_total' => '1.0000',
        'total' => '11.0000',
    ], $overrides));

    if ($withLine) {
        PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], PurchasingSeed::taxGroup('SUBO'.strtoupper(substr($order, -6))));
    }

    return $order;
}

function puSubmitOrderRefused(PurchasingRefusal $reason, string $orderId, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puSubmitOrder($orderId, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-55: asks the gate once, inside the submit, with the order, the submitting actor, the branch, the exact total and the four context fields', function () {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk, ['total' => '1234.5600', 'currency' => 'EUR']);
    $submitter = AccessSeed::actorHolding('submitter', [PurchasingPermissions::ORDERS_MANAGE], $desk['branch']);

    $gate = FakeWorkflowGate::bind();

    puSubmitOrder(strtolower($order), strtolower($submitter));

    expect($gate->evaluations)->toHaveCount(1)
        ->and($gate->evaluations[0]['entityType'])->toBe(WorkflowEntityType::PurchaseOrder)
        ->and($gate->evaluations[0]['context']->entityId)->toBe($order)
        ->and($gate->evaluations[0]['context']->requestedBy)->toBe($submitter)
        ->and($gate->evaluations[0]['context']->branchId)->toBe($desk['branch'])
        ->and($gate->evaluations[0]['context']->amount)->toBe('1234.5600')
        ->and($gate->evaluations[0]['context']->fields)->toBe([
            'supplier_id' => $desk['supplier'],
            'location_id' => $desk['location'],
            'currency' => 'EUR',
            'line_count' => 1,
        ]);
});

it('AC-56: NotRequired approves at once, writes one audit row saying so and who submitted, and the approved event', function () {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk);
    FakeWorkflowGate::bind();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puSubmitOrder($order, $desk['buyer']);

    expect($result->changed)->toBeTrue()
        ->and($result->status)->toBe('approved')
        ->and($result->workflowInstanceId)->toBeNull()
        ->and(PurchasingSeed::row('purchase_orders', $order))->toMatchArray([
            'status' => 'approved',
            'workflow_instance_id' => null,
            'updated_at' => '2026-11-17 10:00:00.000',
            'updated_by' => $desk['buyer'],
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $desk['buyer'],
        'branch_id' => $desk['branch'],
        'action' => PurchasingAuditActions::ORDER_APPROVED,
        'subject_type' => 'purchase_order',
        'subject_id' => $order,
    ])
        ->and($audit['before']['status'])->toBe('draft')
        ->and($audit['after']['status'])->toBe('approved')
        ->and($audit['after']['note'])->toBe(SubmitPurchaseOrderHandler::NOT_REQUIRED_NOTE);

    $events = PurchasingOutbox::events();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('purchase_order.approved')
        ->and($events[0]['aggregate_type'])->toBe('purchase_order')
        ->and($events[0]['aggregate_id'])->toBe($order)
        ->and($events[0]['branch_id'])->toBe($desk['branch'])
        ->and($events[0]['payload']['order_id'])->toBe($order)
        ->and($events[0]['payload']['location_id'])->toBe($desk['location'])
        ->and($events[0]['payload']['lines'])->toHaveCount(1)
        ->and($events[0]['payload']['lines'][0])->toHaveKeys(['line_id', 'product_id', 'base_quantity', 'received_base_quantity'])
        ->and($events[0]['payload']['lines'][0]['product_id'])->toBe($desk['product'])
        ->and($events[0]['payload']['lines'][0]['received_base_quantity'])->toBe('0.0000');
});

it('AC-57: Pending, against the real engine, stores the instance id, moves to pending_approval, audits the submit and writes no event; a second submit opens no second instance', function () {
    $desk = PurchasingWorld::desk();
    PurchasingSeed::workflowDefinition('purchase_order', '10', 'stop', PurchasingWorld::approver('apr')['role']);
    $order = puSubmittableOrder($desk);

    $result = puSubmitOrder($order, $desk['buyer']);

    expect($result->status)->toBe('pending_approval')
        ->and($result->workflowInstanceId)->not->toBeNull()
        ->and(PurchasingSeed::row('purchase_orders', $order))->toMatchArray(['status' => 'pending_approval', 'workflow_instance_id' => $result->workflowInstanceId])
        ->and(PurchasingSeed::connection()->table('workflow_instances')->count())->toBe(1)
        ->and(PurchasingOutbox::events())->toBe([]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::ORDER_SUBMITTED)
        ->and($audit['after']['workflow_instance_id'])->toBe($result->workflowInstanceId);

    $before = PurchasingRefusals::state();

    $again = puSubmitOrder($order, $desk['buyer']);

    expect($again->changed)->toBeFalse()
        ->and($again->status)->toBe('pending_approval')
        ->and($again->workflowInstanceId)->toBe($result->workflowInstanceId)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-56: a repeat submit of an approved order asks the gate nothing and writes nothing', function () {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk, ['status' => 'approved']);
    $gate = FakeWorkflowGate::bind();
    $before = PurchasingRefusals::state();

    $result = puSubmitOrder($order, $desk['buyer']);

    expect($result->changed)->toBeFalse()
        ->and($result->status)->toBe('approved')
        ->and($gate->evaluations)->toBe([])
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-58: an engine failure keeps the order a draft, stores no instance, writes no audit row or event, and is workflow_failed with the engine exception behind it', function () {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk);
    $gate = FakeWorkflowGate::bind();
    $failure = WorkflowEvaluationFailed::engineError(new RuntimeException('boom'));
    $gate->decision = $failure;

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::WorkflowFailed, fn () => puSubmitOrder($order, $desk['buyer']));

    expect($refused->getPrevious())->toBe($failure)
        ->and($refused->detail)->toBe('engineError')
        ->and($gate->evaluations)->toHaveCount(1)
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::row('purchase_orders', $order)['status'])->toBe('draft');
});

it('AC-58: Pending that names no instance is workflow_failed and rolls everything back', function () {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk);
    $gate = FakeWorkflowGate::bind();
    $gate->decision = new WorkflowDecision(WorkflowOutcome::Pending);

    puSubmitOrderRefused(PurchasingRefusal::WorkflowFailed, $order, $desk['buyer']);
});

it('AC-54: refusals are judged before the gate is asked, and each leaves everything as it was', function (string $case, PurchasingRefusal $reason) {
    $desk = PurchasingWorld::desk();
    $withLine = $case !== 'no lines';
    $order = puSubmittableOrder($desk, [], $withLine);

    match ($case) {
        'no lines' => null,
        'inactive supplier' => PurchasingSeed::connection()->table('suppliers')->where('id', $desk['supplier'])->update(['is_active' => false]),
        'deleted supplier' => PurchasingSeed::connection()->table('suppliers')->where('id', $desk['supplier'])->update(['deleted_at' => PurchasingSeed::NOW]),
        'inactive location' => PurchasingSeed::connection()->table('locations')->where('id', $desk['location'])->update(['is_active' => false]),
        'location of another branch' => PurchasingSeed::connection()->table('locations')->where('id', $desk['location'])->update(['branch_id' => PurchasingSeed::branch()]),
        default => null,
    };

    $gate = FakeWorkflowGate::bind();

    puSubmitOrderRefused($reason, $order, $desk['buyer'], $case);

    expect($gate->evaluations)->toBe([]);
})->with([
    'no lines' => ['no lines', PurchasingRefusal::NoLines],
    'inactive supplier' => ['inactive supplier', PurchasingRefusal::SupplierInactive],
    'deleted supplier' => ['deleted supplier', PurchasingRefusal::SupplierInactive],
    'inactive location' => ['inactive location', PurchasingRefusal::UnknownLocation],
    'location of another branch' => ['location of another branch', PurchasingRefusal::UnknownLocation],
]);

it('AC-66 and AC-67: a received, closed or cancelled order is order_closed, and a partly received or sent one is invalid_transition', function (string $status, PurchasingRefusal $reason) {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk, ['status' => $status]);
    $gate = FakeWorkflowGate::bind();

    puSubmitOrderRefused($reason, $order, $desk['buyer'], $status);

    expect($gate->evaluations)->toBe([]);
})->with([
    'received' => ['received', PurchasingRefusal::OrderClosed],
    'closed' => ['closed', PurchasingRefusal::OrderClosed],
    'cancelled' => ['cancelled', PurchasingRefusal::OrderClosed],
    'partially_received' => ['partially_received', PurchasingRefusal::InvalidTransition],
    'sent' => ['sent', PurchasingRefusal::InvalidTransition],
]);

it('AC-54: submit does not re-quote or alter any stored figure', function () {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk);
    FakeWorkflowGate::bind();
    $lines = PurchasingSeed::connection()->table('purchase_order_items')->orderBy('id')->get()->map(static fn (object $r): array => (array) $r)->all();

    puSubmitOrder($order, $desk['buyer']);

    $stored = PurchasingSeed::row('purchase_orders', $order);

    expect($stored)->toMatchArray(['subtotal' => '10.0000', 'tax_total' => '1.0000', 'total' => '11.0000'])
        ->and(PurchasingSeed::connection()->table('purchase_order_items')->orderBy('id')->get()->map(static fn (object $r): array => (array) $r)->all())->toBe($lines);
});

it('AC-84 and AC-85: the manage key at the stored branch is the key; the view key, another branch s holder, a request key and no actor are refused', function () {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk);
    FakeWorkflowGate::bind();

    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::ORDERS_VIEW], $desk['branch']);
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::ORDERS_MANAGE], PurchasingSeed::branch());
    $requests = AccessSeed::actorHolding('requests', [PurchasingPermissions::REQUESTS_MANAGE], $desk['branch']);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puSubmitOrder($order, $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puSubmitOrder($order, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puSubmitOrder($order, $requests));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puSubmitOrder($order, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk);
    PurchasingSeed::connection()->table('employees')->where('id', $desk['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::capture(fn () => puSubmitOrder($order, $desk['buyer']));

    expect($refused->reason)->toBe(PurchasingRefusal::NotAuthorised)
        ->and($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('an unknown order is not_found and a malformed id is validation', function () {
    $desk = PurchasingWorld::desk();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puSubmitOrder((string) Str::ulid(), $desk['buyer']));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puSubmitOrder('nope', $desk['buyer']));
});

it('Ruling 7: the order header is the first statement in the transaction and is locked for update, the supplier is share-locked after it, and the gate comes after both', function () {
    $desk = PurchasingWorld::desk();
    $order = puSubmittableOrder($desk);
    FakeWorkflowGate::bind();

    $log = PurchasingQueryLog::duringLocked(fn () => puSubmitOrder($order, $desk['buyer']));
    $inside = PurchasingQueryLog::inTransaction($log);

    $supplierRead = array_values(array_filter($inside, static fn (string $sql): bool => str_contains($sql, '"suppliers"')));

    expect($inside[0])->toContain('"purchase_orders"')->and($inside[0])->toContain('/* for update */')
        ->and($supplierRead)->not->toBe([])
        ->and($supplierRead[0])->toContain('/* lock in share mode */');
});

<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\CancelPurchaseOrder;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowInstanceStatus;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\CancelPurchaseOrder\CancelPurchaseOrder;
use Flexipos\Purchasing\Features\CancelPurchaseOrder\CancelPurchaseOrderHandler;
use Flexipos\Purchasing\Features\CancelPurchaseOrder\CancelPurchaseOrderResult;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrder;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrderHandler;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\FakeWorkflowGate;
use Flexipos\Purchasing\Tests\Support\PurchasingOutbox;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 19b - CancelPurchaseOrder (AC-64, AC-66, AC-67, AC-97, D-45)
|--------------------------------------------------------------------------
|
| From draft, pending_approval and approved with nothing received. A pending order's
| instance is cancelled through the gate; an approved cancel needs a reason and writes
| the cancelled event; any received base or application row refuses.
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
function puCancelOrder(array $fields, ?string $actorId): CancelPurchaseOrderResult
{
    /** @var CancelPurchaseOrderHandler $handler */
    $handler = app(CancelPurchaseOrderHandler::class);

    return $handler->handle(new CancelPurchaseOrder($fields, $actorId));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{desk: array<string, string>, order: string, line: string}
 */
function puCancelWorld(string $status = 'draft', array $overrides = []): array
{
    $desk = PurchasingWorld::desk('c1');
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], array_merge([
        'status' => $status,
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'total' => '11.0000',
    ], $overrides));

    return [
        'desk' => $desk,
        'order' => $order,
        'line' => PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], PurchasingSeed::taxGroup('CAN')),
    ];
}

/**
 * Submit the world's draft order to the real engine and return the instance it
 * opened, so a test can then bind a fake gate over it. A definition is registered
 * for the order's amount first, and the set-up's audit and outbox rows are cleared.
 *
 * @param  array{desk: array<string, string>, order: string, line: string}  $w
 */
function puCancelPendingInstance(array $w): string
{
    PurchasingSeed::workflowDefinition('purchase_order', '10', 'stop', PurchasingWorld::approver('aprc')['role']);

    $submitted = app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($w['order'], $w['desk']['buyer']));

    PurchasingSeed::connection()->table('audit_logs')->delete();
    PurchasingOutbox::clear();

    return (string) $submitted->workflowInstanceId;
}

/**
 * @param  array<string, mixed>  $fields
 */
function puCancelOrderRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puCancelOrder($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-64: cancels a draft with no reason, one audit row, no event, and never deletes the row', function () {
    $w = puCancelWorld('draft');
    $gate = FakeWorkflowGate::bind();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puCancelOrder(['order_id' => strtolower($w['order'])], strtolower($w['desk']['buyer']));

    expect($result->changed)->toBeTrue()
        ->and($gate->cancels)->toBe([])
        ->and(PurchasingSeed::row('purchase_orders', $w['order']))->toMatchArray([
            'status' => 'cancelled',
            'updated_at' => '2026-11-17 10:00:00.000',
            'updated_by' => $w['desk']['buyer'],
        ])
        ->and(PurchasingOutbox::events())->toBe([]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $w['desk']['buyer'],
        'branch_id' => $w['desk']['branch'],
        'action' => PurchasingAuditActions::ORDER_CANCELLED,
        'subject_type' => 'purchase_order',
        'subject_id' => $w['order'],
    ])
        ->and($audit['before']['status'])->toBe('draft')
        ->and($audit['after']['status'])->toBe('cancelled')
        ->and($audit['after'])->not->toHaveKey('reason');
});

it('AC-64: cancelling an approved order needs a reason, stores it in the audit row, and writes the cancelled event with the order s lines', function () {
    $w = puCancelWorld('approved');

    puCancelOrderRefused(PurchasingRefusal::Validation, ['order_id' => $w['order']], $w['desk']['buyer'], 'no reason');
    puCancelOrderRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'reason' => '   '], $w['desk']['buyer'], 'blank reason');

    $result = puCancelOrder(['order_id' => $w['order'], 'reason' => ' Supplier closed '], $w['desk']['buyer']);

    expect($result->changed)->toBeTrue()
        ->and(PurchasingSeed::row('purchase_orders', $w['order'])['status'])->toBe('cancelled')
        ->and(PurchasingSeed::onlyAuditRow()['after']['reason'])->toBe('Supplier closed');

    $events = PurchasingOutbox::events();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('purchase_order.cancelled')
        ->and($events[0]['payload']['order_id'])->toBe($w['order'])
        ->and($events[0]['payload']['location_id'])->toBe($w['desk']['location'])
        ->and($events[0]['payload']['lines'])->toHaveCount(1)
        ->and($events[0]['payload']['lines'][0]['line_id'])->toBe($w['line']);
});

it('AC-64: an optional reason that is present is still checked, on every status', function () {
    $w = puCancelWorld('draft');

    puCancelOrderRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'reason' => str_repeat('a', 501)], $w['desk']['buyer']);
    puCancelOrderRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'reason' => 7], $w['desk']['buyer']);

    expect(puCancelOrder(['order_id' => $w['order'], 'reason' => str_repeat('a', 500)], $w['desk']['buyer'])->changed)->toBeTrue();
});

it('AC-64: a second cancel succeeds and writes nothing', function () {
    $w = puCancelWorld('draft');

    puCancelOrder(['order_id' => $w['order']], $w['desk']['buyer']);
    $before = PurchasingRefusals::state();

    $again = puCancelOrder(['order_id' => $w['order']], $w['desk']['buyer']);

    expect($again->changed)->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-64: a pending order s instance is cancelled through the gate with the actor and the reason, and the instance id stays as the trace', function () {
    $w = puCancelWorld('draft');
    $instance = puCancelPendingInstance($w);
    $gate = FakeWorkflowGate::bind();
    $gate->states[$instance] = FakeWorkflowGate::state($instance, 'purchase_order', $w['order'], WorkflowInstanceStatus::Pending);

    $result = puCancelOrder(['order_id' => $w['order'], 'reason' => 'no longer needed'], $w['desk']['buyer']);

    expect($result->changed)->toBeTrue()
        ->and($gate->cancels)->toBe([['instanceId' => $instance, 'actor' => $w['desk']['buyer'], 'comment' => 'no longer needed']])
        ->and(PurchasingSeed::row('purchase_orders', $w['order']))->toMatchArray(['status' => 'cancelled', 'workflow_instance_id' => $instance])
        ->and(PurchasingOutbox::events())->toBe([]);
});

it('AC-64: against the real engine a pending order s instance is cancelled, and the audit row names the instance status', function () {
    $desk = PurchasingWorld::desk('c2');
    PurchasingSeed::workflowDefinition('purchase_order', '10', 'stop', PurchasingWorld::approver('apr')['role']);
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['created_by' => $desk['buyer'], 'updated_by' => $desk['buyer'], 'total' => '11.0000']);
    PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], PurchasingSeed::taxGroup('CAR'));

    $submitted = app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($order, $desk['buyer']));
    PurchasingSeed::connection()->table('audit_logs')->delete();
    PurchasingOutbox::clear();

    $result = puCancelOrder(['order_id' => $order, 'reason' => 'changed our mind'], $desk['buyer']);

    expect($result->changed)->toBeTrue()
        ->and(PurchasingSeed::row('purchase_orders', $order))->toMatchArray(['status' => 'cancelled', 'workflow_instance_id' => $submitted->workflowInstanceId])
        ->and(PurchasingSeed::row('workflow_instances', (string) $submitted->workflowInstanceId)['status'])->toBe('cancelled')
        ->and(PurchasingOutbox::events())->toBe([]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['after']['workflow_status'])->toBe('cancelled')
        ->and($audit['after']['reason'])->toBe('changed our mind');
});

it('AC-64: a pending order whose instance was already decided but not synced still cancels, and the audit row records the status the instance had', function () {
    $desk = PurchasingWorld::desk('c3');
    PurchasingSeed::workflowDefinition('purchase_order', '10', 'stop', PurchasingWorld::approver('apr')['role']);
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['created_by' => $desk['buyer'], 'updated_by' => $desk['buyer'], 'total' => '11.0000']);
    PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], PurchasingSeed::taxGroup('CAD'));

    $submitted = app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($order, $desk['buyer']));
    PurchasingWorld::decide((string) $submitted->workflowInstanceId, WorkflowVerdict::Approve);
    PurchasingSeed::connection()->table('audit_logs')->delete();

    $result = puCancelOrder(['order_id' => $order], $desk['buyer']);

    expect($result->changed)->toBeTrue()
        ->and(PurchasingSeed::row('purchase_orders', $order)['status'])->toBe('cancelled')
        ->and(PurchasingSeed::onlyAuditRow()['after']['workflow_status'])->toBe('approved');
});

it('AC-64: any other engine refusal is workflow_failed and rolls the cancel back', function () {
    $w = puCancelWorld('draft');
    $instance = puCancelPendingInstance($w);
    $gate = FakeWorkflowGate::bind();
    $gate->cancelFailure = WorkflowActionRefused::unknownInstance($instance);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::WorkflowFailed, fn () => puCancelOrder(['order_id' => $w['order']], $w['desk']['buyer']));

    expect($refused->detail)->toBe('unknownInstance')
        ->and($gate->cancels)->toHaveCount(1)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-64 and D-45: any received base quantity, or any receipt-application row even one reversed to zero, is already_received, and a partly received or received order says close it instead', function (string $case) {
    $status = in_array($case, ['partially_received', 'received'], true) ? $case : 'approved';
    $w = puCancelWorld($status);

    if ($case === 'received base') {
        PurchasingSeed::connection()->table('purchase_order_items')->where('id', $w['line'])->update(['received_base_quantity' => '0.5000']);
    } elseif ($case === 'reversed application') {
        PurchasingSeed::receiptApplication($w['order'], $w['line'], '1.0000', '1.0000');
        PurchasingSeed::receiptApplication($w['order'], $w['line'], '-1.0000', '-1.0000');
    }

    puCancelOrderRefused(PurchasingRefusal::AlreadyReceived, ['order_id' => $w['order'], 'reason' => 'x'], $w['desk']['buyer'], $case);
})->with([
    'received base' => ['received base'],
    'reversed application' => ['reversed application'],
    'partially_received' => ['partially_received'],
    'received' => ['received'],
]);

it('AC-67: a closed or sent order cannot be cancelled: invalid_transition naming the status', function (string $status) {
    $w = puCancelWorld($status);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::InvalidTransition, fn () => puCancelOrder(['order_id' => $w['order'], 'reason' => 'x'], $w['desk']['buyer']));

    expect($refused->getMessage())->toContain($status)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'closed' => ['closed'],
    'sent' => ['sent'],
]);

it('AC-91: an unknown field, a missing id, an unknown order and a malformed id are refused', function () {
    $w = puCancelWorld('draft');

    puCancelOrderRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'status' => 'x'], $w['desk']['buyer']);
    puCancelOrderRefused(PurchasingRefusal::Validation, [], $w['desk']['buyer']);
    puCancelOrderRefused(PurchasingRefusal::Validation, ['order_id' => 'nope'], $w['desk']['buyer']);
    puCancelOrderRefused(PurchasingRefusal::NotFound, ['order_id' => (string) Str::ulid()], $w['desk']['buyer']);
});

it('AC-85: the manage key at the stored branch is the key; view, reopen, another branch s holder and no actor are refused', function () {
    $w = puCancelWorld('draft');
    $viewer = AccessSeed::actorHolding('viewerc', [PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::ORDERS_REOPEN], $w['desk']['branch']);
    $scoped = AccessSeed::actorHolding('scopedc', [PurchasingPermissions::ORDERS_MANAGE], PurchasingSeed::branch());
    $fields = ['order_id' => $w['order']];

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCancelOrder($fields, $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCancelOrder($fields, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puCancelOrder($fields, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $w = puCancelWorld('draft');
    PurchasingSeed::connection()->table('employees')->where('id', $w['desk']['buyer'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::capture(fn () => puCancelOrder(['order_id' => $w['order']], $w['desk']['buyer']));

    expect($refused->reason)->toBe(PurchasingRefusal::NotAuthorised)
        ->and($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

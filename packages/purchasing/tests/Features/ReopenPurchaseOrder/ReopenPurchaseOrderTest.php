<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\ReopenPurchaseOrder;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Events\NewEvent;
use Flexipos\Contracts\Events\Outbox;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\ReopenPurchaseOrder\ReopenPurchaseOrder;
use Flexipos\Purchasing\Features\ReopenPurchaseOrder\ReopenPurchaseOrderHandler;
use Flexipos\Purchasing\Features\ReopenPurchaseOrder\ReopenPurchaseOrderResult;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrder;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrderHandler;
use Flexipos\Purchasing\Features\SyncOrderApproval\SyncOrderApproval;
use Flexipos\Purchasing\Features\SyncOrderApproval\SyncOrderApprovalHandler;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\PurchasingOutbox;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 19b - ReopenPurchaseOrder (AC-62, AC-66, AC-67, AC-97, D-45)
|--------------------------------------------------------------------------
|
| An approved order with nothing received goes back to draft, its instance cleared,
| and a new approval runs on re-submit. Anything received, or any application row
| even one reversed to zero, refuses; the other statuses refuse by their own rule.
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
function puReopenOrder(array $fields, ?string $actorId): ReopenPurchaseOrderResult
{
    /** @var ReopenPurchaseOrderHandler $handler */
    $handler = app(ReopenPurchaseOrderHandler::class);

    return $handler->handle(new ReopenPurchaseOrder($fields, $actorId));
}

/**
 * An order with one line on a desk whose buyer also holds the reopen key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{desk: array<string, string>, order: string, line: string, reopener: string}
 */
function puReopenWorld(string $status = 'approved', array $overrides = []): array
{
    $desk = PurchasingWorld::desk('o1');
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], array_merge([
        'status' => $status,
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'total' => '11.0000',
    ], $overrides));

    return [
        'desk' => $desk,
        'order' => $order,
        'line' => PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], PurchasingSeed::taxGroup('REO')),
        'reopener' => AccessSeed::actorHolding('reopener', [PurchasingPermissions::ORDERS_REOPEN], $desk['branch']),
    ];
}

/**
 * @param  array<string, mixed>  $fields
 */
function puReopenRefused(PurchasingRefusal $reason, array $fields, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puReopenOrder($fields, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-62: reopens an approved order with nothing received: draft, instance cleared, one audit row with the reason and the old instance id, and the reopened event', function () {
    $w = puReopenWorld('approved');
    $instance = PurchasingSeed::connection()->table('workflow_instances')->count();
    expect($instance)->toBe(0);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puReopenOrder(['order_id' => strtolower($w['order']), 'reason' => '  Wrong supplier  '], strtolower($w['reopener']));

    expect($result->status)->toBe('draft')
        ->and($result->previousInstanceId)->toBeNull()
        ->and(PurchasingSeed::row('purchase_orders', $w['order']))->toMatchArray([
            'status' => 'draft',
            'workflow_instance_id' => null,
            'updated_by' => $w['reopener'],
            'updated_at' => '2026-11-17 10:00:00.000',
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $w['reopener'],
        'branch_id' => $w['desk']['branch'],
        'action' => PurchasingAuditActions::ORDER_REOPENED,
        'subject_type' => 'purchase_order',
        'subject_id' => $w['order'],
    ])
        ->and($audit['before']['status'])->toBe('approved')
        ->and($audit['after']['status'])->toBe('draft')
        ->and($audit['after']['reason'])->toBe('Wrong supplier');

    $events = PurchasingOutbox::events();

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('purchase_order.reopened')
        ->and($events[0]['branch_id'])->toBe($w['desk']['branch'])
        ->and($events[0]['payload']['order_id'])->toBe($w['order'])
        ->and($events[0]['payload']['lines'][0]['line_id'])->toBe($w['line']);
});

it('AC-62: an approved order that went through approval clears its instance id, records it in the audit row, and a re-submit runs a new approval', function () {
    $desk = PurchasingWorld::desk('o2');
    PurchasingSeed::workflowDefinition('purchase_order', '10', 'stop', PurchasingWorld::approver('apr')['role']);
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['created_by' => $desk['buyer'], 'updated_by' => $desk['buyer'], 'total' => '11.0000']);
    PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], PurchasingSeed::taxGroup('REI'));
    $reopener = AccessSeed::actorHolding('reopener2', [PurchasingPermissions::ORDERS_REOPEN], $desk['branch']);

    $submitted = app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($order, $desk['buyer']));
    PurchasingWorld::decide((string) $submitted->workflowInstanceId, WorkflowVerdict::Approve);
    app(SyncOrderApprovalHandler::class)->handle(new SyncOrderApproval($order, $desk['buyer']));
    PurchasingSeed::connection()->table('audit_logs')->delete();

    $result = puReopenOrder(['order_id' => $order, 'reason' => 'redo'], $reopener);

    expect($result->previousInstanceId)->toBe($submitted->workflowInstanceId)
        ->and(PurchasingSeed::row('purchase_orders', $order)['workflow_instance_id'])->toBeNull()
        ->and(PurchasingSeed::onlyAuditRow()['after']['previous_workflow_instance_id'])->toBe($submitted->workflowInstanceId)
        ->and(PurchasingSeed::onlyAuditRow()['before']['workflow_instance_id'])->toBe($submitted->workflowInstanceId);

    $again = app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($order, $desk['buyer']));

    expect($again->status)->toBe('pending_approval')
        ->and($again->workflowInstanceId)->not->toBe($submitted->workflowInstanceId)
        ->and(PurchasingSeed::connection()->table('workflow_instances')->count())->toBe(2);
});

it('AC-62 and D-45: any received base quantity, or any receipt-application row even one reversed to zero, is already_received', function (string $case) {
    $w = puReopenWorld('approved');

    if ($case === 'received base') {
        PurchasingSeed::connection()->table('purchase_order_items')->where('id', $w['line'])->update(['received_base_quantity' => '0.5000']);
    } else {
        // One application and its reversal: the line is back at zero, the rows remain.
        PurchasingSeed::receiptApplication($w['order'], $w['line'], '1.0000', '1.0000');
        PurchasingSeed::receiptApplication($w['order'], $w['line'], '-1.0000', '-1.0000');
    }

    puReopenRefused(PurchasingRefusal::AlreadyReceived, ['order_id' => $w['order'], 'reason' => 'x'], $w['reopener'], $case);
})->with([
    'received base' => ['received base'],
    'reversed application' => ['reversed application'],
]);

it('AC-62, AC-66 and AC-67: partially_received is already_received, received, closed and cancelled are order_closed, and draft, pending_approval and sent are invalid_transition', function (string $status, PurchasingRefusal $reason) {
    $w = puReopenWorld($status);

    puReopenRefused($reason, ['order_id' => $w['order'], 'reason' => 'x'], $w['reopener'], $status);
})->with([
    'partially_received' => ['partially_received', PurchasingRefusal::AlreadyReceived],
    'received' => ['received', PurchasingRefusal::OrderClosed],
    'closed' => ['closed', PurchasingRefusal::OrderClosed],
    'cancelled' => ['cancelled', PurchasingRefusal::OrderClosed],
    'draft' => ['draft', PurchasingRefusal::InvalidTransition],
    'pending_approval' => ['pending_approval', PurchasingRefusal::InvalidTransition],
    'sent' => ['sent', PurchasingRefusal::InvalidTransition],
]);

it('AC-62: a missing, blank, non-string or over-long reason is validation, and 500 characters is accepted', function (mixed $reason, bool $accepted) {
    $w = puReopenWorld('approved');
    $fields = ['order_id' => $w['order']];

    if ($reason !== 'omit') {
        $fields['reason'] = $reason;
    }

    if ($accepted) {
        expect(puReopenOrder($fields, $w['reopener'])->status)->toBe('draft');

        return;
    }

    puReopenRefused(PurchasingRefusal::Validation, $fields, $w['reopener']);
})->with([
    'omitted' => ['omit', false],
    'blank' => ['   ', false],
    'null' => [null, false],
    'int' => [5, false],
    '501' => [str_repeat('a', 501), false],
    '500' => [str_repeat('a', 500), true],
]);

it('AC-91: a field the operation does not take is validation, and an unknown order is not_found', function () {
    $w = puReopenWorld('approved');

    puReopenRefused(PurchasingRefusal::Validation, ['order_id' => $w['order'], 'reason' => 'x', 'status' => 'draft'], $w['reopener']);
    puReopenRefused(PurchasingRefusal::Validation, ['reason' => 'x'], $w['reopener']);
    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puReopenOrder(['order_id' => (string) Str::ulid(), 'reason' => 'x'], $w['reopener']));
});

it('AC-62 and AC-85: the reopen key at the stored branch is the key; manage, close, another branch s holder and no actor are refused', function () {
    $w = puReopenWorld('approved');
    $manager = $w['desk']['buyer'];
    $closer = AccessSeed::actorHolding('closer', [PurchasingPermissions::ORDERS_CLOSE], $w['desk']['branch']);
    $scoped = AccessSeed::actorHolding('scopedr', [PurchasingPermissions::ORDERS_REOPEN], PurchasingSeed::branch());
    $fields = ['order_id' => $w['order'], 'reason' => 'x'];

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puReopenOrder($fields, $manager));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puReopenOrder($fields, $closer));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puReopenOrder($fields, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puReopenOrder($fields, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-86: a suspended employee is refused with the resolver s reason', function () {
    $w = puReopenWorld('approved');
    PurchasingSeed::connection()->table('employees')->where('id', $w['reopener'])->update(['status' => 'suspended']);

    $refused = PurchasingRefusals::capture(fn () => puReopenOrder(['order_id' => $w['order'], 'reason' => 'x'], $w['reopener']));

    expect($refused->reason)->toBe(PurchasingRefusal::NotAuthorised)
        ->and($refused->cause)->toBe(RefusalReason::EmployeeUnavailable);
});

it('AC-90: a failing event write rolls the reopen back, audit row included', function () {
    $w = puReopenWorld('approved');

    app()->instance(Outbox::class, new class implements Outbox
    {
        public function record(NewEvent $event): string
        {
            throw new RuntimeException('outbox down');
        }
    });

    $before = PurchasingSeed::row('purchase_orders', $w['order']);

    expect(fn () => puReopenOrder(['order_id' => $w['order'], 'reason' => 'x'], $w['reopener']))->toThrow(RuntimeException::class)
        ->and(PurchasingSeed::row('purchase_orders', $w['order']))->toBe($before)
        ->and(PurchasingSeed::audit())->toBe([]);
});

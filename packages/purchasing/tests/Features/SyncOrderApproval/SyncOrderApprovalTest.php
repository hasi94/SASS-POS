<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\SyncOrderApproval;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowInstanceStatus;
use Flexipos\Contracts\Workflow\WorkflowOnReject;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrder;
use Flexipos\Purchasing\Features\SubmitPurchaseOrder\SubmitPurchaseOrderHandler;
use Flexipos\Purchasing\Features\SyncOrderApproval\SyncOrderApproval;
use Flexipos\Purchasing\Features\SyncOrderApproval\SyncOrderApprovalHandler;
use Flexipos\Purchasing\Features\SyncOrderApproval\SyncOrderApprovalResult;
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
| T-034 step 19a - SyncOrderApproval (AC-59, AC-97, D-50, D-52, Q-B1)
|--------------------------------------------------------------------------
|
| Every outcome against the real engine on the real workflow tables: approved,
| rejected with each on_reject, cancelled, expired, still pending, and a repeat. The
| rejected + stop row is a cancel with the Q-B1 audit action and no cancel event.
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

function puSyncOrder(string $orderId, ?string $actorId): SyncOrderApprovalResult
{
    /** @var SyncOrderApprovalHandler $handler */
    $handler = app(SyncOrderApprovalHandler::class);

    return $handler->handle(new SyncOrderApproval($orderId, $actorId));
}

/**
 * An order submitted to the real engine and now pending.
 *
 * @param  array<string, string>|null  $desk
 * @return array{desk: array<string, string>, order: string, instance: string}
 */
function puPendingOrder(string $onReject = 'stop', ?array $desk = null): array
{
    $desk ??= PurchasingWorld::desk();

    // One definition per test: a second one at the same priority would be ambiguous.
    if (! PurchasingSeed::connection()->table('workflow_definitions')->where('entity_type', 'purchase_order')->exists()) {
        PurchasingSeed::workflowDefinition('purchase_order', '10', $onReject, PurchasingWorld::approver('apr')['role']);
    }

    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], [
        'created_by' => $desk['buyer'],
        'updated_by' => $desk['buyer'],
        'subtotal' => '10.0000',
        'tax_total' => '1.0000',
        'total' => '11.0000',
    ]);
    PurchasingSeed::orderItem($order, $desk['product'], $desk['unit'], PurchasingSeed::taxGroup('SYN'.strtoupper(substr($order, -6))));

    $submitted = app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($order, $desk['buyer']));
    PurchasingSeed::connection()->table('audit_logs')->delete();
    PurchasingOutbox::clear();

    return ['desk' => $desk, 'order' => $order, 'instance' => (string) $submitted->workflowInstanceId];
}

it('AC-59: an approved instance makes the order approved, keeps the instance id, writes one audit row naming the workflow status and the approved event', function () {
    $made = puPendingOrder();
    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Approve);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puSyncOrder(strtolower($made['order']), $made['desk']['buyer']);

    expect($result->changed)->toBeTrue()
        ->and($result->status)->toBe('approved')
        ->and(PurchasingSeed::row('purchase_orders', $made['order']))->toMatchArray([
            'status' => 'approved',
            'workflow_instance_id' => $made['instance'],
            'updated_at' => '2026-11-17 10:00:00.000',
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $made['desk']['buyer'],
        'branch_id' => $made['desk']['branch'],
        'action' => PurchasingAuditActions::ORDER_APPROVED,
        'subject_id' => $made['order'],
    ])
        ->and($audit['before']['status'])->toBe('pending_approval')
        ->and($audit['after']['status'])->toBe('approved')
        ->and($audit['after']['workflow_status'])->toBe('approved')
        ->and(PurchasingOutbox::types())->toBe(['purchase_order.approved']);
});

it('AC-59: rejected with return_to_requester returns the order to draft with the instance id cleared and no event', function () {
    $made = puPendingOrder('return_to_requester');
    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Reject);

    $result = puSyncOrder($made['order'], $made['desk']['buyer']);

    expect($result->status)->toBe('draft')
        ->and(PurchasingSeed::row('purchase_orders', $made['order']))->toMatchArray(['status' => 'draft', 'workflow_instance_id' => null])
        ->and(PurchasingOutbox::events())->toBe([]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::ORDER_RETURNED_TO_DRAFT)
        ->and($audit['after']['workflow_status'])->toBe('rejected');
});

it('AC-59 and Q-B1: rejected with stop cancels the order under the cancelled action with the workflow status in the after image, keeps the instance id and writes no cancel event', function () {
    $made = puPendingOrder('stop');
    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Reject);

    $result = puSyncOrder($made['order'], $made['desk']['buyer']);

    expect($result->status)->toBe('cancelled')
        ->and(PurchasingSeed::row('purchase_orders', $made['order']))->toMatchArray(['status' => 'cancelled', 'workflow_instance_id' => $made['instance']])
        ->and(PurchasingOutbox::events())->toBe([]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::ORDER_CANCELLED)
        ->and($audit['before']['status'])->toBe('pending_approval')
        ->and($audit['after']['status'])->toBe('cancelled')
        ->and($audit['after']['workflow_status'])->toBe('rejected');
});

it('AC-59: a cancelled instance returns the order to draft with the instance id cleared, and it can be submitted again for a new instance', function () {
    $made = puPendingOrder();
    app(WorkflowGate::class)->cancel($made['instance']);

    $result = puSyncOrder($made['order'], $made['desk']['buyer']);

    expect($result->status)->toBe('draft')
        ->and(PurchasingSeed::row('purchase_orders', $made['order']))->toMatchArray(['status' => 'draft', 'workflow_instance_id' => null]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::ORDER_RETURNED_TO_DRAFT)
        ->and($audit['after']['workflow_status'])->toBe('cancelled');

    $again = app(SubmitPurchaseOrderHandler::class)->handle(new SubmitPurchaseOrder($made['order'], $made['desk']['buyer']));

    expect($again->status)->toBe('pending_approval')
        ->and($again->workflowInstanceId)->not->toBe($made['instance'])
        ->and(PurchasingSeed::connection()->table('workflow_instances')->count())->toBe(2);
});

it('AC-59: an expired instance returns the order to draft with the instance id cleared', function () {
    $made = puPendingOrder();
    PurchasingSeed::connection()->table('workflow_instances')->where('id', $made['instance'])->update(['status' => 'expired']);

    $result = puSyncOrder($made['order'], $made['desk']['buyer']);

    expect($result->status)->toBe('draft')
        ->and(PurchasingSeed::row('purchase_orders', $made['order'])['workflow_instance_id'])->toBeNull()
        ->and(PurchasingSeed::onlyAuditRow()['after']['workflow_status'])->toBe('expired');
});

it('AC-59: a still-pending instance changes nothing and writes nothing', function () {
    $made = puPendingOrder();
    $before = PurchasingRefusals::state();

    $result = puSyncOrder($made['order'], $made['desk']['buyer']);

    expect($result->changed)->toBeFalse()
        ->and($result->status)->toBe('pending_approval')
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-59: syncing again after the outcome is applied is a no-op with no audit row and no second event', function () {
    $made = puPendingOrder();
    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Approve);

    puSyncOrder($made['order'], $made['desk']['buyer']);
    $before = PurchasingRefusals::state();

    $again = puSyncOrder($made['order'], $made['desk']['buyer']);

    expect($again->changed)->toBeFalse()
        ->and($again->status)->toBe('approved')
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-59: an order that is not pending_approval is returned unchanged without asking the engine', function (string $status) {
    $desk = PurchasingWorld::desk();
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['status' => $status]);
    $gate = FakeWorkflowGate::bind();
    $before = PurchasingRefusals::state();

    $result = puSyncOrder($order, $desk['buyer']);

    expect($result->changed)->toBeFalse()
        ->and($result->status)->toBe($status)
        ->and($gate->instanceReads)->toBe([])
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'draft' => ['draft'],
    'approved' => ['approved'],
    'partially_received' => ['partially_received'],
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
]);

it('AC-59 and Ruling 6: an instance that belongs to another order is never applied, even when it is approved', function () {
    $a = puPendingOrder();
    $b = puPendingOrder('stop', $a['desk']);

    // A points at B's instance, and B's is approved.
    PurchasingSeed::connection()->table('purchase_orders')->where('id', $a['order'])->update(['workflow_instance_id' => $b['instance']]);
    PurchasingWorld::decide($b['instance'], WorkflowVerdict::Approve);

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::InvalidTransition, fn () => puSyncOrder($a['order'], $a['desk']['buyer']));

    expect(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::row('purchase_orders', $a['order'])['status'])->toBe('pending_approval');
});

it('Ruling 6: an instance of another entity type or id, or one the engine does not know, is never applied', function (string $case) {
    $made = puPendingOrder();
    $gate = FakeWorkflowGate::bind();

    $gate->states[$made['instance']] = match ($case) {
        'another type' => FakeWorkflowGate::state($made['instance'], 'purchase_request', $made['order'], WorkflowInstanceStatus::Approved),
        'another entity id' => FakeWorkflowGate::state($made['instance'], 'purchase_order', (string) Str::ulid(), WorkflowInstanceStatus::Approved),
        default => WorkflowActionRefused::unknownInstance($made['instance']),
    };

    $before = PurchasingRefusals::state();

    $expected = $case === 'unknown instance' ? PurchasingRefusal::WorkflowFailed : PurchasingRefusal::InvalidTransition;

    PurchasingRefusals::expect($expected, fn () => puSyncOrder($made['order'], $made['desk']['buyer']));

    expect(PurchasingRefusals::state())->toBe($before);
})->with([
    'another type' => ['another type'],
    'another entity id' => ['another entity id'],
    'unknown instance' => ['unknown instance'],
]);

it('Ruling 6: a pending_approval order that holds no instance id is invalid_transition', function () {
    $desk = PurchasingWorld::desk();
    $order = PurchasingSeed::order($desk['supplier'], $desk['branch'], $desk['location'], ['status' => 'pending_approval']);
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::InvalidTransition, fn () => puSyncOrder($order, $desk['buyer']));

    expect(PurchasingRefusals::state())->toBe($before);
});

it('AC-59: the fake reads the instance once, by id, and applies the on_reject the engine reports', function () {
    $made = puPendingOrder();
    $gate = FakeWorkflowGate::bind();
    $gate->states[$made['instance']] = FakeWorkflowGate::state($made['instance'], 'purchase_order', $made['order'], WorkflowInstanceStatus::Rejected, WorkflowOnReject::ReturnToRequester);

    $result = puSyncOrder($made['order'], $made['desk']['buyer']);

    expect($gate->instanceReads)->toBe([$made['instance']])
        ->and($result->status)->toBe('draft');
});

it('AC-59 and D-50: the view key at the order s branch is the key, so a manager of another branch, a request key holder and a missing actor are refused', function () {
    $made = puPendingOrder();
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::ORDERS_VIEW], PurchasingSeed::branch());
    $requests = AccessSeed::actorHolding('requests', [PurchasingPermissions::REQUESTS_VIEW], $made['desk']['branch']);
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::ORDERS_VIEW], $made['desk']['branch']);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puSyncOrder($made['order'], $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puSyncOrder($made['order'], $requests));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puSyncOrder($made['order'], null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);

    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Approve);

    expect(puSyncOrder($made['order'], $viewer)->status)->toBe('approved');
});

it('an unknown order is not_found and a malformed id is validation', function () {
    $desk = PurchasingWorld::desk();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puSyncOrder((string) Str::ulid(), $desk['buyer']));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puSyncOrder('nope', $desk['buyer']));
});

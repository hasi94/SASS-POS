<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\SyncRequestApproval;

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
use Flexipos\Purchasing\Features\SubmitPurchaseRequest\SubmitPurchaseRequest;
use Flexipos\Purchasing\Features\SubmitPurchaseRequest\SubmitPurchaseRequestHandler;
use Flexipos\Purchasing\Features\SyncRequestApproval\SyncRequestApproval;
use Flexipos\Purchasing\Features\SyncRequestApproval\SyncRequestApprovalHandler;
use Flexipos\Purchasing\Features\SyncRequestApproval\SyncRequestApprovalResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\FakeWorkflowGate;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 13 - SyncRequestApproval (AC-28, AC-38, D-50, D-52)
|--------------------------------------------------------------------------
|
| Every outcome against the real engine on the real workflow tables: approved,
| rejected with either on_reject, cancelled, expired, still pending, and a repeat.
| The instance must be this request's own. Nothing triggers this slice (D-52); the
| tests call it, as whoever decides an approval must.
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

function puSyncRequest(string $requestId, ?string $actorId): SyncRequestApprovalResult
{
    /** @var SyncRequestApprovalHandler $handler */
    $handler = app(SyncRequestApprovalHandler::class);

    return $handler->handle(new SyncRequestApproval($requestId, $actorId));
}

/**
 * A request submitted to the real engine and now pending.
 *
 * @param  array<string, string>|null  $desk
 * @return array{desk: array<string, string>, request: string, instance: string}
 */
function puPendingRequest(string $onReject = 'stop', ?array $desk = null): array
{
    $desk ??= PurchasingWorld::desk();
    // One definition per test: a second one at the same priority would be ambiguous.
    if (! PurchasingSeed::connection()->table('workflow_definitions')->where('entity_type', 'purchase_request')->exists()) {
        PurchasingSeed::workflowDefinition('purchase_request', '10', $onReject, PurchasingWorld::approver('apr')['role']);
    }

    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    PurchasingSeed::requestItem($request, $desk['product'], $desk['unit'], 1, ['quantity' => '5.0000', 'base_quantity' => '5.0000', 'estimated_cost' => '2.5000']);

    $submitted = app(SubmitPurchaseRequestHandler::class)->handle(new SubmitPurchaseRequest($request, $desk['buyer']));
    PurchasingSeed::connection()->table('audit_logs')->delete();

    return ['desk' => $desk, 'request' => $request, 'instance' => (string) $submitted->workflowInstanceId];
}

it('AC-28: an approved instance makes the request approved, keeps the instance id and writes one audit row naming the workflow status', function () {
    $made = puPendingRequest();
    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Approve);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puSyncRequest(strtolower($made['request']), $made['desk']['buyer']);

    expect($result->changed)->toBeTrue()
        ->and($result->status)->toBe('approved')
        ->and(PurchasingSeed::row('purchase_requests', $made['request']))->toMatchArray([
            'status' => 'approved',
            'workflow_instance_id' => $made['instance'],
            'updated_at' => '2026-11-17 10:00:00.000',
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $made['desk']['buyer'],
        'branch_id' => $made['desk']['branch'],
        'action' => PurchasingAuditActions::REQUEST_APPROVED,
        'subject_id' => $made['request'],
    ])
        ->and($audit['before']['status'])->toBe('pending_approval')
        ->and($audit['after']['status'])->toBe('approved')
        ->and($audit['after']['workflow_status'])->toBe('approved');
});

it('AC-28: a rejected instance makes the request rejected for either on_reject, keeping the instance id', function (string $onReject) {
    $made = puPendingRequest($onReject);
    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Reject);

    $result = puSyncRequest($made['request'], $made['desk']['buyer']);

    expect($result->status)->toBe('rejected')
        ->and(PurchasingSeed::row('purchase_requests', $made['request']))->toMatchArray([
            'status' => 'rejected',
            'workflow_instance_id' => $made['instance'],
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::REQUEST_REJECTED)
        ->and($audit['after']['workflow_status'])->toBe('rejected');
})->with([
    'stop' => ['stop'],
    'return_to_requester' => ['return_to_requester'],
]);

it('AC-28: a cancelled instance returns the request to draft with the instance id cleared, and the request can be submitted again for a new instance', function () {
    $made = puPendingRequest();
    app(WorkflowGate::class)->cancel($made['instance']);

    $result = puSyncRequest($made['request'], $made['desk']['buyer']);

    expect($result->status)->toBe('draft')
        ->and(PurchasingSeed::row('purchase_requests', $made['request']))->toMatchArray(['status' => 'draft', 'workflow_instance_id' => null]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::REQUEST_RETURNED_TO_DRAFT)
        ->and($audit['after']['workflow_status'])->toBe('cancelled');

    $again = app(SubmitPurchaseRequestHandler::class)->handle(new SubmitPurchaseRequest($made['request'], $made['desk']['buyer']));

    expect($again->status)->toBe('pending_approval')
        ->and($again->workflowInstanceId)->not->toBe($made['instance'])
        ->and(PurchasingSeed::connection()->table('workflow_instances')->count())->toBe(2);
});

it('AC-28: an expired instance returns the request to draft with the instance id cleared', function () {
    $made = puPendingRequest();
    PurchasingSeed::connection()->table('workflow_instances')->where('id', $made['instance'])->update(['status' => 'expired']);

    $result = puSyncRequest($made['request'], $made['desk']['buyer']);

    expect($result->status)->toBe('draft')
        ->and(PurchasingSeed::row('purchase_requests', $made['request'])['workflow_instance_id'])->toBeNull()
        ->and(PurchasingSeed::onlyAuditRow()['after']['workflow_status'])->toBe('expired');
});

it('AC-28: a still-pending instance changes nothing and writes nothing', function () {
    $made = puPendingRequest();
    $before = PurchasingRefusals::state();

    $result = puSyncRequest($made['request'], $made['desk']['buyer']);

    expect($result->changed)->toBeFalse()
        ->and($result->status)->toBe('pending_approval')
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-28: syncing again after the outcome is applied is a no-op with no audit row', function () {
    $made = puPendingRequest();
    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Approve);

    puSyncRequest($made['request'], $made['desk']['buyer']);
    $before = PurchasingRefusals::state();

    $again = puSyncRequest($made['request'], $made['desk']['buyer']);

    expect($again->changed)->toBeFalse()
        ->and($again->status)->toBe('approved')
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-28: a request that is not pending_approval is returned unchanged without asking the engine', function (string $status) {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => $status]);
    $gate = FakeWorkflowGate::bind();
    $before = PurchasingRefusals::state();

    $result = puSyncRequest($request, $desk['buyer']);

    expect($result->changed)->toBeFalse()
        ->and($result->status)->toBe($status)
        ->and($gate->instanceReads)->toBe([])
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'draft' => ['draft'],
    'approved' => ['approved'],
    'rejected' => ['rejected'],
    'converted' => ['converted'],
    'cancelled' => ['cancelled'],
]);

it('AC-28 and Ruling 6: an instance that belongs to another request is never applied, even when it is approved', function () {
    $a = puPendingRequest();
    $b = puPendingRequest('stop', $a['desk']);

    // A points at B's instance, and B's is approved.
    PurchasingSeed::connection()->table('purchase_requests')->where('id', $a['request'])->update(['workflow_instance_id' => $b['instance']]);
    PurchasingWorld::decide($b['instance'], WorkflowVerdict::Approve);

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::InvalidTransition, fn () => puSyncRequest($a['request'], $a['desk']['buyer']));

    expect(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::row('purchase_requests', $a['request'])['status'])->toBe('pending_approval');
});

it('Ruling 6: an instance of another entity type, or one the engine does not know, is never applied', function (string $case) {
    $made = puPendingRequest();
    $gate = FakeWorkflowGate::bind();

    $gate->states[$made['instance']] = match ($case) {
        'another type' => FakeWorkflowGate::state($made['instance'], 'purchase_order', $made['request'], WorkflowInstanceStatus::Approved),
        'another entity id' => FakeWorkflowGate::state($made['instance'], 'purchase_request', (string) Str::ulid(), WorkflowInstanceStatus::Approved),
        default => WorkflowActionRefused::unknownInstance($made['instance']),
    };

    $before = PurchasingRefusals::state();

    $expected = $case === 'unknown instance' ? PurchasingRefusal::WorkflowFailed : PurchasingRefusal::InvalidTransition;

    PurchasingRefusals::expect($expected, fn () => puSyncRequest($made['request'], $made['desk']['buyer']));

    expect(PurchasingRefusals::state())->toBe($before);
})->with([
    'another type' => ['another type'],
    'another entity id' => ['another entity id'],
    'unknown instance' => ['unknown instance'],
]);

it('Ruling 6: a pending_approval request that holds no instance id is invalid_transition', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => 'pending_approval']);

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::InvalidTransition, fn () => puSyncRequest($request, $desk['buyer']));

    expect(PurchasingRefusals::state())->toBe($before);
});

it('Ruling 6: the fake reads the instance once, by id, and applies the on_reject the engine reports', function () {
    $made = puPendingRequest();
    $gate = FakeWorkflowGate::bind();
    $gate->states[$made['instance']] = FakeWorkflowGate::state($made['instance'], 'purchase_request', $made['request'], WorkflowInstanceStatus::Rejected, WorkflowOnReject::ReturnToRequester);

    $result = puSyncRequest($made['request'], $made['desk']['buyer']);

    expect($gate->instanceReads)->toBe([$made['instance']])
        ->and($result->status)->toBe('rejected');
});

it('AC-38 and D-50: the view key at the request s branch is the key, so a manager without it, another branch s holder and a missing actor are refused', function () {
    $made = puPendingRequest();
    $managerOnly = AccessSeed::actorHolding('manager_only', [PurchasingPermissions::REQUESTS_MANAGE], $made['desk']['branch']);
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::REQUESTS_VIEW], PurchasingSeed::branch());
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::REQUESTS_VIEW], $made['desk']['branch']);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puSyncRequest($made['request'], $managerOnly));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puSyncRequest($made['request'], $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puSyncRequest($made['request'], null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);

    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Approve);

    expect(puSyncRequest($made['request'], $viewer)->status)->toBe('approved');
});

it('an unknown request is not_found and a malformed id is validation', function () {
    $desk = PurchasingWorld::desk();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puSyncRequest((string) Str::ulid(), $desk['buyer']));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puSyncRequest('nope', $desk['buyer']));
});

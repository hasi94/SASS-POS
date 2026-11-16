<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\CancelPurchaseRequest;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowInstanceStatus;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\CancelPurchaseRequest\CancelPurchaseRequest;
use Flexipos\Purchasing\Features\CancelPurchaseRequest\CancelPurchaseRequestHandler;
use Flexipos\Purchasing\Features\CancelPurchaseRequest\CancelPurchaseRequestResult;
use Flexipos\Purchasing\Features\SubmitPurchaseRequest\SubmitPurchaseRequest;
use Flexipos\Purchasing\Features\SubmitPurchaseRequest\SubmitPurchaseRequestHandler;
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
| T-034 step 13 - CancelPurchaseRequest (AC-29, AC-37; Ruling 6)
|--------------------------------------------------------------------------
|
| From draft, pending_approval and approved; a pending instance is cancelled in the
| engine; a decided-but-unsynced instance does not stop the cancel and the audit row
| records what it was; an engine refusal rolls everything back.
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

function puCancelRequest(string $requestId, ?string $actorId): CancelPurchaseRequestResult
{
    /** @var CancelPurchaseRequestHandler $handler */
    $handler = app(CancelPurchaseRequestHandler::class);

    return $handler->handle(new CancelPurchaseRequest($requestId, $actorId));
}

/**
 * A request submitted to the real engine and now pending.
 *
 * @return array{desk: array<string, string>, request: string, instance: string}
 */
function puCancelPending(): array
{
    $desk = PurchasingWorld::desk();
    PurchasingSeed::workflowDefinition('purchase_request', '10', 'stop', PurchasingWorld::approver('apr')['role']);

    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    PurchasingSeed::requestItem($request, $desk['product'], $desk['unit'], 1, ['quantity' => '5.0000', 'base_quantity' => '5.0000', 'estimated_cost' => '2.5000']);

    $submitted = app(SubmitPurchaseRequestHandler::class)->handle(new SubmitPurchaseRequest($request, $desk['buyer']));
    PurchasingSeed::connection()->table('audit_logs')->delete();

    return ['desk' => $desk, 'request' => $request, 'instance' => (string) $submitted->workflowInstanceId];
}

it('AC-29: cancels a draft and an approved request, with one audit row each', function (string $status) {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => $status]);
    $gate = FakeWorkflowGate::bind();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puCancelRequest(strtolower($request), $desk['buyer']);

    expect($result->changed)->toBeTrue()
        ->and($gate->cancels)->toBe([])
        ->and(PurchasingSeed::row('purchase_requests', $request))->toMatchArray([
            'status' => 'cancelled',
            'updated_at' => '2026-11-17 10:00:00.000',
            'updated_by' => $desk['buyer'],
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $desk['buyer'],
        'branch_id' => $desk['branch'],
        'action' => PurchasingAuditActions::REQUEST_CANCELLED,
        'subject_type' => 'purchase_request',
        'subject_id' => $request,
    ])
        ->and($audit['before']['status'])->toBe($status)
        ->and($audit['after']['status'])->toBe('cancelled');
})->with([
    'draft' => ['draft'],
    'approved' => ['approved'],
]);

it('AC-29: a second cancel of a cancelled request succeeds and writes nothing', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);

    puCancelRequest($request, $desk['buyer']);
    $before = PurchasingRefusals::state();

    $again = puCancelRequest($request, $desk['buyer']);

    expect($again->changed)->toBeFalse()
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('AC-29 and AC-37: a rejected or converted request cannot be cancelled: invalid_transition naming the status, and nothing is written', function (string $status) {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => $status]);
    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::InvalidTransition, fn () => puCancelRequest($request, $desk['buyer']));

    expect($refused->getMessage())->toContain($status)
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'rejected' => ['rejected'],
    'converted' => ['converted'],
]);

it('AC-29: against the real engine a pending request is cancelled and so is its instance, and the audit row names the instance s end status', function () {
    $made = puCancelPending();

    $result = puCancelRequest($made['request'], $made['desk']['buyer']);

    expect($result->changed)->toBeTrue()
        ->and(PurchasingSeed::row('purchase_requests', $made['request']))->toMatchArray([
            'status' => 'cancelled',
            'workflow_instance_id' => $made['instance'],
        ])
        ->and(PurchasingSeed::row('workflow_instances', $made['instance'])['status'])->toBe('cancelled');

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::REQUEST_CANCELLED)
        ->and($audit['before']['status'])->toBe('pending_approval')
        ->and($audit['after']['workflow_status'])->toBe('cancelled');
});

it('Ruling 6: a pending request whose instance was decided but never synced is still cancelled, and the audit row records the instance status', function () {
    $made = puCancelPending();
    PurchasingWorld::decide($made['instance'], WorkflowVerdict::Approve);

    $result = puCancelRequest($made['request'], $made['desk']['buyer']);

    expect($result->changed)->toBeTrue()
        ->and(PurchasingSeed::row('purchase_requests', $made['request'])['status'])->toBe('cancelled')
        ->and(PurchasingSeed::row('workflow_instances', $made['instance'])['status'])->toBe('approved')
        ->and(PurchasingSeed::onlyAuditRow()['after']['workflow_status'])->toBe('approved');
});

it('Ruling 6: the engine is asked to cancel the instance once, by id, as the actor', function () {
    $made = puCancelPending();
    $gate = FakeWorkflowGate::bind();
    $gate->states[$made['instance']] = FakeWorkflowGate::state($made['instance'], 'purchase_request', $made['request'], WorkflowInstanceStatus::Pending);

    puCancelRequest($made['request'], $made['desk']['buyer']);

    expect($gate->cancels)->toHaveCount(1)
        ->and($gate->cancels[0]['instanceId'])->toBe($made['instance'])
        ->and($gate->cancels[0]['actor'])->toBe($made['desk']['buyer']);
});

it('Ruling 6: any other engine refusal is workflow_failed and rolls everything back, the request staying pending', function (WorkflowActionRefused $failure) {
    $made = puCancelPending();
    $gate = FakeWorkflowGate::bind();
    $gate->cancelFailure = $failure;

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::WorkflowFailed, fn () => puCancelRequest($made['request'], $made['desk']['buyer']));

    expect($refused->getPrevious())->toBe($failure)
        ->and($refused->detail)->toBe($failure->reason)
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::row('purchase_requests', $made['request'])['status'])->toBe('pending_approval');
})->with([
    'unknown instance' => [fn () => WorkflowActionRefused::unknownInstance('01JABCDEFGHJKMNPQRSTVWXYZ0')],
    'not the assignee' => [fn () => WorkflowActionRefused::notAssignee('01JABCDEFGHJKMNPQRSTVWXYZ0')],
]);

it('AC-38 and AC-84: the manage key at the stored branch is the key, so the view key alone, another branch s holder and a missing actor are refused', function () {
    $desk = PurchasingWorld::desk();
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer']);
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::REQUESTS_MANAGE], PurchasingSeed::branch());
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::REQUESTS_VIEW], $desk['branch']);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCancelRequest($request, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puCancelRequest($request, $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puCancelRequest($request, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('an unknown request is not_found and a malformed id is validation', function () {
    $desk = PurchasingWorld::desk();

    PurchasingRefusals::expect(PurchasingRefusal::NotFound, fn () => puCancelRequest((string) Str::ulid(), $desk['buyer']));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puCancelRequest('nope', $desk['buyer']));
});

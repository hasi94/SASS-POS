<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Features\SubmitPurchaseRequest;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Contracts\Workflow\WorkflowDecision;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowOutcome;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Features\AddPurchaseRequestLine\AddPurchaseRequestLine;
use Flexipos\Purchasing\Features\AddPurchaseRequestLine\AddPurchaseRequestLineHandler;
use Flexipos\Purchasing\Features\SubmitPurchaseRequest\SubmitPurchaseRequest;
use Flexipos\Purchasing\Features\SubmitPurchaseRequest\SubmitPurchaseRequestHandler;
use Flexipos\Purchasing\Features\SubmitPurchaseRequest\SubmitPurchaseRequestResult;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Tests\Support\FakeWorkflowGate;
use Flexipos\Purchasing\Tests\Support\PurchasingQueryLog;
use Flexipos\Purchasing\Tests\Support\PurchasingRefusals;
use Flexipos\Purchasing\Tests\Support\PurchasingSeed;
use Flexipos\Purchasing\Tests\Support\PurchasingWorld;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-034 step 13 - SubmitPurchaseRequest (AC-26, AC-27, AC-37; Rulings 5 to 7)
|--------------------------------------------------------------------------
|
| The fake gate proves the call shape and the fail-closed dataset; the real engine
| on the real workflow tables proves what a Pending decision does to the request.
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

function puSubmitRequest(string $requestId, ?string $actorId): SubmitPurchaseRequestResult
{
    /** @var SubmitPurchaseRequestHandler $handler */
    $handler = app(SubmitPurchaseRequestHandler::class);

    return $handler->handle(new SubmitPurchaseRequest($requestId, $actorId));
}

/**
 * A request on the desk with these lines: `[quantity, estimated_cost]`.
 *
 * @param  array<string, string>  $desk
 * @param  list<array{0: string, 1: ?string}>  $lines
 */
function puRequestOf(array $desk, array $lines, string $status = 'draft'): string
{
    $request = PurchasingSeed::request($desk['branch'], $desk['location'], $desk['buyer'], ['status' => $status]);

    foreach ($lines as $index => [$quantity, $cost]) {
        PurchasingSeed::requestItem($request, $desk['product'], $desk['unit'], $index + 1, [
            'quantity' => $quantity,
            'base_quantity' => $quantity,
            'estimated_cost' => $cost,
        ]);
    }

    return $request;
}

function puSubmitRefused(PurchasingRefusal $reason, string $requestId, ?string $actorId, ?string $context = null): void
{
    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect($reason, fn () => puSubmitRequest($requestId, $actorId), $context);

    expect(PurchasingRefusals::state())->toBe($before);
}

it('AC-26: asks the gate once, inside the submit, with the request, its requester, the branch, the exact amount and the location and line count', function () {
    $desk = PurchasingWorld::desk();
    // 5 x 2.5 = 12.5, 3 x 1.3333 = 3.9999, and a line with no estimate adds nothing.
    $request = puRequestOf($desk, [['5.0000', '2.5000'], ['3.0000', '1.3333'], ['9.0000', null]]);
    $other = AccessSeed::actorHolding('submitter', [PurchasingPermissions::REQUESTS_MANAGE], $desk['branch']);

    $gate = FakeWorkflowGate::bind();

    puSubmitRequest(strtolower($request), $other);

    expect($gate->evaluations)->toHaveCount(1)
        ->and($gate->evaluations[0]['entityType'])->toBe(WorkflowEntityType::PurchaseRequest)
        ->and($gate->evaluations[0]['context']->entityId)->toBe($request)
        ->and($gate->evaluations[0]['context']->requestedBy)->toBe($desk['buyer'])
        ->and($gate->evaluations[0]['context']->branchId)->toBe($desk['branch'])
        ->and($gate->evaluations[0]['context']->amount)->toBe('16.4999')
        ->and($gate->evaluations[0]['context']->fields)->toBe(['location_id' => $desk['location'], 'line_count' => 3]);
});

it('AC-26: a request whose lines carry no estimate is submitted at an amount of zero', function () {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['5.0000', null]]);
    $gate = FakeWorkflowGate::bind();

    puSubmitRequest($request, $desk['buyer']);

    expect($gate->evaluations[0]['context']->amount)->toBe('0.0000');
});

it('AC-26: refuses a request with no lines as no_lines, and never calls the gate', function () {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, []);
    $gate = FakeWorkflowGate::bind();

    puSubmitRefused(PurchasingRefusal::NoLines, $request, $desk['buyer']);

    expect($gate->evaluations)->toBe([]);
});

it('AC-26: an amount that cannot fit DECIMAL(18,4) is out_of_range before the gate is asked anything', function () {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['99999999999999.0000', '99999999999999.0000']]);
    $gate = FakeWorkflowGate::bind();

    puSubmitRefused(PurchasingRefusal::OutOfRange, $request, $desk['buyer']);

    expect($gate->evaluations)->toBe([]);
});

it('AC-27: NotRequired approves at once, with one audit row whose after image says approval was not required', function () {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);
    FakeWorkflowGate::bind();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-17 10:00:00.000'));

    $result = puSubmitRequest($request, $desk['buyer']);

    expect($result->status)->toBe('approved')
        ->and($result->changed)->toBeTrue()
        ->and($result->workflowInstanceId)->toBeNull()
        ->and(PurchasingSeed::row('purchase_requests', $request))->toMatchArray([
            'status' => 'approved',
            'workflow_instance_id' => null,
            'updated_at' => '2026-11-17 10:00:00.000',
            'updated_by' => $desk['buyer'],
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit)->toMatchArray([
        'actor_id' => $desk['buyer'],
        'branch_id' => $desk['branch'],
        'action' => PurchasingAuditActions::REQUEST_APPROVED,
        'subject_type' => 'purchase_request',
        'subject_id' => $request,
    ])
        ->and($audit['before']['status'])->toBe('draft')
        ->and($audit['after']['status'])->toBe('approved')
        ->and($audit['after']['note'])->toBe('approval not required');
});

it('AC-27: a second submit of a submitted request returns it, asks the gate nothing and writes nothing', function (string $status) {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['5.0000', '2.5000']], $status);
    $gate = FakeWorkflowGate::bind();
    $before = PurchasingRefusals::state();

    $result = puSubmitRequest($request, $desk['buyer']);

    expect($result->changed)->toBeFalse()
        ->and($result->status)->toBe($status)
        ->and($gate->evaluations)->toBe([])
        ->and(PurchasingRefusals::state())->toBe($before);
})->with([
    'pending_approval' => ['pending_approval'],
    'approved' => ['approved'],
]);

it('AC-27: submitting twice calls the engine once', function () {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);
    $gate = FakeWorkflowGate::bind();

    puSubmitRequest($request, $desk['buyer']);
    puSubmitRequest($request, $desk['buyer']);

    expect($gate->evaluations)->toHaveCount(1)
        ->and(PurchasingSeed::audit())->toHaveCount(1);
});

it('AC-27 and D-11: every engine failure is workflow_failed and fails closed: the request stays draft, no instance id is stored, nothing is audited', function (string $factory) {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);
    $gate = FakeWorkflowGate::bind();

    $gate->decision = match ($factory) {
        'conditionNotEvaluable' => WorkflowEvaluationFailed::conditionNotEvaluable('d', 'amount', 'no amount'),
        'ambiguousDefinitions' => WorkflowEvaluationFailed::ambiguousDefinitions('purchase_request', 10, ['a', 'b']),
        'definitionCorrupt' => WorkflowEvaluationFailed::definitionCorrupt('d', 'broken'),
        'approverTypeUnavailable' => WorkflowEvaluationFailed::approverTypeUnavailable('manager'),
        'alreadyPending' => WorkflowEvaluationFailed::alreadyPending('01JABCDEFGHJKMNPQRSTVWXYZ0'),
        default => WorkflowEvaluationFailed::engineError(new RuntimeException('down')),
    };

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::WorkflowFailed, fn () => puSubmitRequest($request, $desk['buyer']));

    expect($refused->detail)->toBe($factory)
        ->and($refused->getPrevious())->toBe($gate->decision)
        ->and($refused->cause)->toBeNull()
        ->and($gate->evaluations)->toHaveCount(1)
        ->and(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::row('purchase_requests', $request)['status'])->toBe('draft');
})->with([
    'conditionNotEvaluable' => ['conditionNotEvaluable'],
    'ambiguousDefinitions' => ['ambiguousDefinitions'],
    'definitionCorrupt' => ['definitionCorrupt'],
    'approverTypeUnavailable' => ['approverTypeUnavailable'],
    'alreadyPending' => ['alreadyPending'],
    'engineError' => ['engineError'],
]);

it('D-11: a Pending decision that names no instance is refused rather than stored', function () {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);
    $gate = FakeWorkflowGate::bind();
    $gate->decision = new WorkflowDecision(WorkflowOutcome::Pending);

    puSubmitRefused(PurchasingRefusal::WorkflowFailed, $request, $desk['buyer']);
});

it('AC-37: refuses a request that is rejected, converted or cancelled as invalid_transition naming the status, and never asks the gate', function (string $status) {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['5.0000', '2.5000']], $status);
    $gate = FakeWorkflowGate::bind();

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::InvalidTransition, fn () => puSubmitRequest($request, $desk['buyer']));

    expect($refused->getMessage())->toContain($status)
        ->and($gate->evaluations)->toBe([])
        ->and(PurchasingRefusals::state())->toBe($before);
})->with(array_combine(
    array_map(static fn (RequestStatus $status): string => $status->value, array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => $status->isTerminal()))),
    array_map(static fn (RequestStatus $status): array => [$status->value], array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => $status->isTerminal()))),
));

it('Ruling 7: the header is the first statement locked in the submit transaction, before the lines are read and the gate is asked', function () {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);
    FakeWorkflowGate::bind();

    $log = PurchasingQueryLog::duringLocked(fn () => puSubmitRequest($request, $desk['buyer']));
    $inside = PurchasingQueryLog::inTransaction($log);

    expect($inside[0])->toContain('"purchase_requests"')->and($inside[0])->toContain('/* for update */');

    $lines = array_values(array_filter($inside, static fn (string $sql): bool => str_contains($sql, '"purchase_request_items"')));

    expect($lines)->not->toBe([])
        ->and(array_search($lines[0], $inside, true))->toBeGreaterThan(0);
});

it('AC-21 and AC-38: the permission is asked at the stored branch, and the view key alone is refused', function () {
    $desk = PurchasingWorld::desk();
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);
    $gate = FakeWorkflowGate::bind();
    $scoped = AccessSeed::actorHolding('scoped', [PurchasingPermissions::REQUESTS_MANAGE], PurchasingSeed::branch());
    $viewer = AccessSeed::actorHolding('viewer', [PurchasingPermissions::REQUESTS_VIEW], $desk['branch']);

    $before = PurchasingRefusals::state();

    $refused = PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puSubmitRequest($request, $scoped));
    PurchasingRefusals::expect(PurchasingRefusal::NotAuthorised, fn () => puSubmitRequest($request, $viewer));
    PurchasingRefusals::expect(PurchasingRefusal::Validation, fn () => puSubmitRequest($request, null));

    expect($refused->cause)->toBe(RefusalReason::NotPermitted)
        ->and($gate->evaluations)->toBe([])
        ->and(PurchasingRefusals::state())->toBe($before);
});

it('an unknown request is not_found and a malformed id is validation', function () {
    $desk = PurchasingWorld::desk();

    puSubmitRefused(PurchasingRefusal::NotFound, (string) Str::ulid(), $desk['buyer']);
    puSubmitRefused(PurchasingRefusal::Validation, 'nope', $desk['buyer']);
});

/*
|--------------------------------------------------------------------------
| The real engine
|--------------------------------------------------------------------------
*/

it('AC-27: against the real engine a matching amount is Pending: the instance id is stored, one submitted row is audited, and the request is locked', function () {
    $desk = PurchasingWorld::desk();
    $approver = PurchasingWorld::approver();
    PurchasingSeed::workflowDefinition('purchase_request', '10', 'stop', $approver['role']);
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);

    $result = puSubmitRequest($request, $desk['buyer']);

    $instance = PurchasingSeed::connection()->table('workflow_instances')->where('entity_id', $request)->first();

    expect($instance)->not->toBeNull()
        ->and($result->status)->toBe('pending_approval')
        ->and($result->workflowInstanceId)->toBe(strtoupper((string) $instance?->id))
        ->and((string) $instance?->entity_type)->toBe('purchase_request')
        ->and(PurchasingSeed::row('purchase_requests', $request))->toMatchArray([
            'status' => 'pending_approval',
            'workflow_instance_id' => strtoupper((string) $instance?->id),
        ]);

    $audit = PurchasingSeed::onlyAuditRow();

    expect($audit['action'])->toBe(PurchasingAuditActions::REQUEST_SUBMITTED)
        ->and($audit['after']['status'])->toBe('pending_approval')
        ->and(array_key_exists('note', $audit['after']))->toBeFalse();

    // Locked now (AC-25).
    $line = [
        'id' => (string) Str::ulid(),
        'request_id' => $request,
        'product_id' => $desk['product'],
        'unit_id' => $desk['unit'],
        'quantity' => '1',
    ];

    PurchasingRefusals::expect(PurchasingRefusal::Locked, fn () => app(AddPurchaseRequestLineHandler::class)->handle(new AddPurchaseRequestLine($line, $desk['buyer'])));
});

it('AC-27: against the real engine an amount below the threshold is NotRequired and approves at once, and a second submit opens no second instance', function () {
    $desk = PurchasingWorld::desk();
    $approver = PurchasingWorld::approver();
    PurchasingSeed::workflowDefinition('purchase_request', '100', 'stop', $approver['role']);
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);

    puSubmitRequest($request, $desk['buyer']);
    puSubmitRequest($request, $desk['buyer']);

    expect(PurchasingSeed::row('purchase_requests', $request)['status'])->toBe('approved')
        ->and(PurchasingSeed::connection()->table('workflow_instances')->count())->toBe(0);
});

it('AC-27: against the real engine a second submit of a pending request opens no second instance', function () {
    $desk = PurchasingWorld::desk();
    $approver = PurchasingWorld::approver();
    PurchasingSeed::workflowDefinition('purchase_request', '10', 'stop', $approver['role']);
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);

    $first = puSubmitRequest($request, $desk['buyer']);
    $again = puSubmitRequest($request, $desk['buyer']);

    expect($again->changed)->toBeFalse()
        ->and($again->workflowInstanceId)->toBe($first->workflowInstanceId)
        ->and(PurchasingSeed::connection()->table('workflow_instances')->count())->toBe(1)
        ->and(PurchasingSeed::audit())->toHaveCount(1);
});

it('AC-27: against the real engine an engine failure leaves the request draft with no instance and no audit row', function () {
    $desk = PurchasingWorld::desk();
    $approver = PurchasingWorld::approver();
    $definition = PurchasingSeed::workflowDefinition('purchase_request', '10', 'stop', $approver['role']);
    // A corrupt stored definition: the engine cannot evaluate it.
    PurchasingSeed::connection()->table('workflow_definitions')->where('id', $definition)->update(['conditions' => '[{"field":"amount","operator":"~","value":"1"}]']);
    $request = puRequestOf($desk, [['5.0000', '2.5000']]);

    $before = PurchasingRefusals::state();

    PurchasingRefusals::expect(PurchasingRefusal::WorkflowFailed, fn () => puSubmitRequest($request, $desk['buyer']));

    expect(PurchasingRefusals::state())->toBe($before)
        ->and(PurchasingSeed::row('purchase_requests', $request)['status'])->toBe('draft');
});

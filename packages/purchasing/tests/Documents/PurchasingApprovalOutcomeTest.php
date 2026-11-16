<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Documents;

use Flexipos\Contracts\Workflow\WorkflowInstanceStatus;
use Flexipos\Contracts\Workflow\WorkflowOnReject;
use Flexipos\Purchasing\Documents\ApprovalOutcome;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;

/*
|--------------------------------------------------------------------------
| T-034 step 7 - ApprovalOutcome (Ruling 4, AC-28, AC-59, Q-B1)
|--------------------------------------------------------------------------
|
| Every (workflow status, on_reject) pair for each document kind, with the
| target status, whether the instance id is cleared and the audit action.
*/

it('maps every workflow outcome for a request, with on_reject irrelevant', function (WorkflowInstanceStatus $status, WorkflowOnReject $onReject, ?RequestStatus $to, bool $clears, ?string $action) {
    $outcome = ApprovalOutcome::forRequest($status, $onReject);

    expect($outcome->to)->toBe($to)
        ->and($outcome->clearsInstance)->toBe($clears)
        ->and($outcome->action)->toBe($action)
        ->and($outcome->changes())->toBe($to !== null)
        ->and($outcome->workflowStatus)->toBe($status);
})->with(function () {
    foreach ([WorkflowOnReject::Stop, WorkflowOnReject::ReturnToRequester] as $onReject) {
        yield "approved, {$onReject->value}" => [WorkflowInstanceStatus::Approved, $onReject, RequestStatus::Approved, false, 'purchasing_request_approved'];
        yield "rejected, {$onReject->value}" => [WorkflowInstanceStatus::Rejected, $onReject, RequestStatus::Rejected, false, 'purchasing_request_rejected'];
        yield "cancelled, {$onReject->value}" => [WorkflowInstanceStatus::Cancelled, $onReject, RequestStatus::Draft, true, 'purchasing_request_returned_to_draft'];
        yield "expired, {$onReject->value}" => [WorkflowInstanceStatus::Expired, $onReject, RequestStatus::Draft, true, 'purchasing_request_returned_to_draft'];
        yield "pending, {$onReject->value}" => [WorkflowInstanceStatus::Pending, $onReject, null, false, null];
    }
});

it('maps every workflow outcome for an order, where on_reject decides a rejection', function (WorkflowInstanceStatus $status, WorkflowOnReject $onReject, ?OrderStatus $to, bool $clears, ?string $action) {
    $outcome = ApprovalOutcome::forOrder($status, $onReject);

    expect($outcome->to)->toBe($to)
        ->and($outcome->clearsInstance)->toBe($clears)
        ->and($outcome->action)->toBe($action)
        ->and($outcome->changes())->toBe($to !== null)
        ->and($outcome->workflowStatus)->toBe($status);
})->with(function () {
    yield 'rejected, return_to_requester' => [WorkflowInstanceStatus::Rejected, WorkflowOnReject::ReturnToRequester, OrderStatus::Draft, true, 'purchasing_order_returned_to_draft'];
    yield 'rejected, stop (Q-B1: cancelled, instance kept)' => [WorkflowInstanceStatus::Rejected, WorkflowOnReject::Stop, OrderStatus::Cancelled, false, 'purchasing_order_cancelled'];

    foreach ([WorkflowOnReject::Stop, WorkflowOnReject::ReturnToRequester] as $onReject) {
        yield "approved, {$onReject->value}" => [WorkflowInstanceStatus::Approved, $onReject, OrderStatus::Approved, false, 'purchasing_order_approved'];
        yield "cancelled, {$onReject->value}" => [WorkflowInstanceStatus::Cancelled, $onReject, OrderStatus::Draft, true, 'purchasing_order_returned_to_draft'];
        yield "expired, {$onReject->value}" => [WorkflowInstanceStatus::Expired, $onReject, OrderStatus::Draft, true, 'purchasing_order_returned_to_draft'];
        yield "pending, {$onReject->value}" => [WorkflowInstanceStatus::Pending, $onReject, null, false, null];
    }
});

it('only ever names an action the package declares', function () {
    foreach (WorkflowInstanceStatus::cases() as $status) {
        foreach (WorkflowOnReject::cases() as $onReject) {
            foreach ([ApprovalOutcome::forRequest($status, $onReject), ApprovalOutcome::forOrder($status, $onReject)] as $outcome) {
                if ($outcome->action !== null) {
                    expect(PurchasingAuditActions::ALL)->toContain($outcome->action);
                }
            }
        }
    }
});

it('moves only along transitions the state machines allow, from pending_approval', function () {
    foreach (WorkflowInstanceStatus::cases() as $status) {
        foreach (WorkflowOnReject::cases() as $onReject) {
            $request = ApprovalOutcome::forRequest($status, $onReject)->to;
            $order = ApprovalOutcome::forOrder($status, $onReject)->to;

            if ($request !== null) {
                expect(RequestStatus::PendingApproval->canMoveTo($request))->toBeTrue();
            }

            if ($order !== null) {
                expect(OrderStatus::PendingApproval->canMoveTo($order))->toBeTrue();
            }
        }
    }
});

<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Documents;

use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/*
|--------------------------------------------------------------------------
| T-034 step 7 - the two state machines as full pair datasets (AC-37, AC-67)
|--------------------------------------------------------------------------
|
| Every (from, to) pair, the allowed ones written out here independently of the
| enum, so a transition added to or dropped from the enum turns a row red. A
| status moving to itself is never a transition.
*/

/**
 * @param  class-string<\BackedEnum>  $enum
 * @param  list<array{string, string}>  $allowed
 * @return array<string, array{string, string, bool}>
 */
function puPairs(string $enum, array $allowed): array
{
    $rows = [];

    foreach ($enum::cases() as $from) {
        foreach ($enum::cases() as $to) {
            $rows["{$from->value} -> {$to->value}"] = [$from->value, $to->value, in_array([$from->value, $to->value], $allowed, true)];
        }
    }

    return $rows;
}

const PU_REQUEST_MOVES = [
    ['draft', 'pending_approval'], ['draft', 'approved'], ['draft', 'cancelled'],
    ['pending_approval', 'approved'], ['pending_approval', 'rejected'], ['pending_approval', 'draft'], ['pending_approval', 'cancelled'],
    ['approved', 'converted'], ['approved', 'cancelled'],
];

const PU_ORDER_MOVES = [
    ['draft', 'pending_approval'], ['draft', 'approved'], ['draft', 'cancelled'],
    ['pending_approval', 'approved'], ['pending_approval', 'draft'], ['pending_approval', 'cancelled'],
    ['approved', 'draft'], ['approved', 'partially_received'], ['approved', 'received'], ['approved', 'cancelled'], ['approved', 'closed'],
    ['partially_received', 'received'], ['partially_received', 'closed'], ['partially_received', 'approved'],
    ['received', 'partially_received'], ['received', 'approved'], ['received', 'closed'],
];

it('allows exactly the request transitions of AC-37 and no other, over every pair', function (string $from, string $to, bool $allowed) {
    expect(RequestStatus::from($from)->canMoveTo(RequestStatus::from($to)))->toBe($allowed);

    if ($allowed) {
        RequestStatus::from($from)->assertCanMoveTo(RequestStatus::from($to));
    } else {
        try {
            RequestStatus::from($from)->assertCanMoveTo(RequestStatus::from($to));
            $refused = null;
        } catch (PurchasingRefused $refused) {
        }

        expect($refused)->toBeInstanceOf(PurchasingRefused::class)
            ->and($refused->code())->toBe('invalid_transition')
            ->and($refused->getMessage())->toContain("[{$from}]");
    }
})->with(puPairs(RequestStatus::class, PU_REQUEST_MOVES));

it('allows exactly the order transitions of AC-67 and no other, over every pair', function (string $from, string $to, bool $allowed) {
    expect(OrderStatus::from($from)->canMoveTo(OrderStatus::from($to)))->toBe($allowed);

    if ($allowed) {
        OrderStatus::from($from)->assertCanMoveTo(OrderStatus::from($to));
    } else {
        try {
            OrderStatus::from($from)->assertCanMoveTo(OrderStatus::from($to));
            $refused = null;
        } catch (PurchasingRefused $refused) {
        }

        expect($refused)->toBeInstanceOf(PurchasingRefused::class)
            ->and($refused->code())->toBe('invalid_transition')
            ->and($refused->getMessage())->toContain("[{$from}]");
    }
})->with(puPairs(OrderStatus::class, PU_ORDER_MOVES));

it('holds exactly the stored status vocabulary of the two tables', function () {
    expect(array_map(static fn (RequestStatus $s): string => $s->value, RequestStatus::cases()))
        ->toBe(['draft', 'pending_approval', 'approved', 'rejected', 'converted', 'cancelled'])
        ->and(array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases()))
        ->toBe(['draft', 'pending_approval', 'approved', 'sent', 'partially_received', 'received', 'closed', 'cancelled']);
});

it('lets nothing enter or leave sent (D-2)', function () {
    foreach (OrderStatus::cases() as $other) {
        expect(OrderStatus::Sent->canMoveTo($other))->toBeFalse()
            ->and($other->canMoveTo(OrderStatus::Sent))->toBeFalse();
    }
});

it('knows which request statuses are editable, terminal and live for the supplier delete rule', function () {
    $editable = array_filter(RequestStatus::cases(), static fn (RequestStatus $s): bool => $s->isEditable());
    $terminal = array_filter(RequestStatus::cases(), static fn (RequestStatus $s): bool => $s->isTerminal());
    $live = array_filter(RequestStatus::cases(), static fn (RequestStatus $s): bool => $s->isLive());

    expect(array_values($editable))->toBe([RequestStatus::Draft])
        ->and(array_values($terminal))->toBe([RequestStatus::Rejected, RequestStatus::Converted, RequestStatus::Cancelled])
        ->and(array_values($live))->toBe([RequestStatus::Draft, RequestStatus::PendingApproval, RequestStatus::Approved]);
});

it('knows which order statuses accept a receipt (AC-75, D-44), are closed to edits (AC-66) and hold up a supplier delete (AC-14)', function () {
    $filter = static fn (callable $test): array => array_values(array_filter(OrderStatus::cases(), $test));

    expect($filter(static fn (OrderStatus $s): bool => $s->acceptsReceipt()))
        ->toBe([OrderStatus::Approved, OrderStatus::PartiallyReceived, OrderStatus::Received])
        ->and($filter(static fn (OrderStatus $s): bool => $s->isEditClosed()))
        ->toBe([OrderStatus::Received, OrderStatus::Closed, OrderStatus::Cancelled])
        ->and($filter(static fn (OrderStatus $s): bool => $s->isLive()))
        ->toBe([OrderStatus::Draft, OrderStatus::PendingApproval, OrderStatus::Approved, OrderStatus::Sent, OrderStatus::PartiallyReceived]);
});

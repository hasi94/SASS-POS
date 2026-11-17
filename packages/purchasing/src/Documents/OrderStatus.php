<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/**
 * The purchase order's state machine (T-034 Ruling 4, AC-67, D-2, D-44, D-45).
 *
 * The allowed moves, exactly AC-67: `draft -> pending_approval | approved |
 * cancelled`; `pending_approval -> approved | draft | cancelled`; `approved ->
 * draft (reopen) | partially_received | received | cancelled | closed`;
 * `partially_received -> received | closed | approved` (a reversal to all-zero);
 * `received -> partially_received | approved (a reversal) | closed`. `sent` is in the stored
 * enum and **nothing enters or leaves it** (D-2). `closed` and `cancelled` are
 * terminal.
 *
 * Whether `approved -> draft` and `approved -> cancelled` are allowed for a given
 * order also depends on there being no receipt-application row (D-45), and
 * `approved -> closed` on there being one (an order fully reversed to zero is
 * `approved` again but has received once); that is the slice's question to the
 * table, and this enum answers only the status half.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * Every status this one may move to.
     *
     * @return list<self>
     */
    public function targets(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Approved, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Draft, self::Cancelled],
            self::Approved => [self::Draft, self::PartiallyReceived, self::Received, self::Cancelled, self::Closed],
            self::PartiallyReceived => [self::Received, self::Closed, self::Approved],
            self::Received => [self::PartiallyReceived, self::Approved, self::Closed],
            self::Sent, self::Closed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->targets(), true);
    }

    /**
     * @throws PurchasingRefused invalid_transition, naming the current status
     */
    public function assertCanMoveTo(self $to): void
    {
        if (! $this->canMoveTo($to)) {
            throw PurchasingRefused::because(
                PurchasingRefusal::InvalidTransition,
                "A purchase order that is [{$this->value}] cannot become [{$to->value}].",
            );
        }
    }

    /**
     * Whether a receipt may be applied (AC-75, D-44): `approved`,
     * `partially_received` and `received`. A `received` order accepts only a
     * reversal or an over-receipt, which is the slice's rule on the delta's sign;
     * `draft`, `pending_approval`, `sent`, `closed` and `cancelled` are
     * `order_not_open`.
     */
    public function acceptsReceipt(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyReceived, self::Received], true);
    }

    /**
     * Whether edits, line changes, submit and reopen are refused `order_closed`
     * (AC-66): `received`, `closed` and `cancelled`.
     */
    public function isEditClosed(): bool
    {
        return in_array($this, [self::Received, self::Closed, self::Cancelled], true);
    }

    /**
     * Whether the order still holds up deleting its supplier (AC-14): every
     * status but the terminal ones (`received`, `closed`, `cancelled`).
     */
    public function isLive(): bool
    {
        return ! $this->isEditClosed();
    }
}

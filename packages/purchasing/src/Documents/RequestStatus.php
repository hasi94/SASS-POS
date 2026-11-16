<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/**
 * The purchase request's state machine (T-034 Ruling 4, AC-37, D-2).
 *
 * The allowed moves are the whole truth about which transitions exist:
 * `draft -> pending_approval | approved | cancelled`; `pending_approval ->
 * approved | rejected | draft | cancelled`; `approved -> converted | cancelled`.
 * `rejected`, `converted` and `cancelled` are terminal. Staying where you are is
 * not a move: a repeated submit or cancel is an idempotent no-op decided by the
 * slice, never a transition (Ruling 5).
 *
 * Whether a move is allowed *for this document right now* can depend on more than
 * the status (a convert needs the supplier to be live, a cancel of a converted
 * request is refused here because converted is terminal); this enum answers only
 * the status half.
 */
enum RequestStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Converted = 'converted';
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
            self::PendingApproval => [self::Approved, self::Rejected, self::Draft, self::Cancelled],
            self::Approved => [self::Converted, self::Cancelled],
            self::Rejected, self::Converted, self::Cancelled => [],
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
                "A purchase request that is [{$this->value}] cannot become [{$to->value}].",
            );
        }
    }

    /**
     * Whether header and line edits are allowed: only a `draft` is editable
     * (AC-25). Every other status is `locked`.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether nothing can ever happen to the request again.
     */
    public function isTerminal(): bool
    {
        return $this->targets() === [];
    }

    /**
     * Whether the request still holds up deleting its supplier (AC-14): the three
     * live statuses.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval, self::Approved], true);
    }
}

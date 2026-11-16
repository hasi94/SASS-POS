<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/**
 * Which edits an order accepts in which status (T-034 AC-60, AC-61, AC-63,
 * AC-66, D-12): the one table, so the update slices do not each carry a copy.
 *
 * - **`draft`**: every field, every line change.
 * - **`pending_approval`**: nothing (`locked`); cancel is a transition, not an edit.
 * - **`approved`** (nothing received) and **`partially_received`**: only `notes`,
 *   `terms` and the header `expected_date`, and a line's `expected_date`. In
 *   `partially_received` a line's `expected_date` changes only while that line is
 *   incomplete. Any other field, and any line added or removed, is `locked`
 *   naming the field; in `approved` the message names reopen.
 * - **`received`, `closed`, `cancelled`**: `order_closed`, whatever the field.
 *
 * Pure. Each method refuses by throwing; returning means "apply it". `sent` is
 * never entered by any feature (D-2) and is treated as locked.
 */
final class Amendments
{
    /** The header fields an approved or partly received order still accepts. */
    public const HEADER_FIELDS = ['notes', 'terms', 'expected_date'];

    /** The line field an approved or partly received order still accepts. */
    public const LINE_FIELDS = ['expected_date'];

    /**
     * @param  list<string>  $fields  the header fields the call changes
     *
     * @throws PurchasingRefused locked, order_closed
     */
    public static function assertHeader(OrderStatus $status, array $fields): void
    {
        self::assertOpenForEdit($status);

        if ($status === OrderStatus::Draft) {
            return;
        }

        self::assertAmendable($status, $fields, self::HEADER_FIELDS);
    }

    /**
     * @param  list<string>  $fields  the line fields the call changes
     * @param  bool  $lineComplete  whether the line's received base has reached its ordered base
     *
     * @throws PurchasingRefused locked, order_closed
     */
    public static function assertLine(OrderStatus $status, array $fields, bool $lineComplete): void
    {
        self::assertOpenForEdit($status);

        if ($status === OrderStatus::Draft) {
            return;
        }

        self::assertAmendable($status, $fields, self::LINE_FIELDS);

        if ($status === OrderStatus::PartiallyReceived && $lineComplete && $fields !== []) {
            throw PurchasingRefused::because(
                PurchasingRefusal::Locked,
                'This line is already fully received, so its ['.implode(', ', $fields).'] cannot be changed.',
            );
        }
    }

    /**
     * Adding or removing a line: draft only (AC-61, AC-49).
     *
     * @throws PurchasingRefused locked, order_closed
     */
    public static function assertLineSetChange(OrderStatus $status): void
    {
        self::assertOpenForEdit($status);

        if ($status !== OrderStatus::Draft) {
            throw PurchasingRefused::because(
                PurchasingRefusal::Locked,
                "Lines cannot be added or removed while the order is [{$status->value}].".self::reopenHint($status),
            );
        }
    }

    /**
     * @throws PurchasingRefused order_closed
     */
    private static function assertOpenForEdit(OrderStatus $status): void
    {
        if ($status->isEditClosed()) {
            throw PurchasingRefused::because(
                PurchasingRefusal::OrderClosed,
                "The order is [{$status->value}] and no longer accepts changes.",
            );
        }
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $amendable
     *
     * @throws PurchasingRefused locked
     */
    private static function assertAmendable(OrderStatus $status, array $fields, array $amendable): void
    {
        if ($status === OrderStatus::PendingApproval || $status === OrderStatus::Sent) {
            throw PurchasingRefused::because(
                PurchasingRefusal::Locked,
                "The order is [{$status->value}] and cannot be edited.",
            );
        }

        $refused = array_values(array_diff($fields, $amendable));

        if ($refused !== []) {
            throw PurchasingRefused::because(
                PurchasingRefusal::Locked,
                'The order is ['.$status->value.']; these fields cannot be changed: '.implode(', ', $refused)
                    .'. Only '.implode(', ', $amendable).' can be changed.'.self::reopenHint($status),
            );
        }
    }

    /**
     * An approved order with nothing received can be reopened (AC-62); a partly
     * received one cannot, so only the first message names the way out.
     */
    private static function reopenHint(OrderStatus $status): string
    {
        return $status === OrderStatus::Approved ? ' Reopen the order to change anything else.' : '';
    }
}

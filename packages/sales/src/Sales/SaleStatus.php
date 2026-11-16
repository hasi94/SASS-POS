<?php

declare(strict_types=1);

namespace Flexipos\Sales\Sales;

/**
 * `sales.status` (`docs/database.md` section 6.7). `failed` is never reached by
 * anything this task ships (Ruling 4).
 */
enum SaleStatus: string
{
    case Draft = 'draft';
    case Held = 'held';
    case PendingPayment = 'pending_payment';
    case Completed = 'completed';
    case Voided = 'voided';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Failed = 'failed';

    /**
     * The statuses in which a sale can still take or lose a tender, and so the
     * ones that make a payment method "in use" (AC-16).
     *
     * @return list<string>
     */
    public static function open(): array
    {
        return [self::Draft->value, self::Held->value, self::PendingPayment->value];
    }

    /**
     * The statuses whose tenders are money that really moved through the
     * drawer: a finished sale, or one that has since been refunded in part or
     * whole. A voided sale nets to zero, and a draft, held or pending sale has
     * taken nothing yet (AC-34, F-15).
     *
     * @return list<string>
     */
    public static function settled(): array
    {
        return [self::Completed->value, self::Refunded->value, self::PartiallyRefunded->value];
    }
}

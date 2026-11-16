<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Registry;

/**
 * The audit actions this package writes through `AuditTrail::record()` (T-034
 * Ruling 12, D-31): exactly the spec preamble's 28 snake-case `purchasing_*`
 * names, nothing else.
 *
 * **Named constants, not a list of handler constants.** The slices that write
 * these arrive in later chunks, so the names live here once and each handler
 * will pass the constant; a handler can then only write an action this class
 * declares, and the registry test pins the list against the spec. No literal is
 * copied into a handler.
 */
final class PurchasingAuditActions
{
    /** The module every action below belongs to. */
    public const MODULE = 'purchasing';

    public const SUPPLIER_CREATED = 'purchasing_supplier_created';

    public const SUPPLIER_UPDATED = 'purchasing_supplier_updated';

    public const SUPPLIER_DEACTIVATED = 'purchasing_supplier_deactivated';

    public const SUPPLIER_ACTIVATED = 'purchasing_supplier_activated';

    public const SUPPLIER_DELETED = 'purchasing_supplier_deleted';

    public const REQUEST_CREATED = 'purchasing_request_created';

    public const REQUEST_UPDATED = 'purchasing_request_updated';

    public const REQUEST_LINE_ADDED = 'purchasing_request_line_added';

    public const REQUEST_LINE_UPDATED = 'purchasing_request_line_updated';

    public const REQUEST_LINE_REMOVED = 'purchasing_request_line_removed';

    public const REQUEST_SUBMITTED = 'purchasing_request_submitted';

    public const REQUEST_APPROVED = 'purchasing_request_approved';

    public const REQUEST_REJECTED = 'purchasing_request_rejected';

    public const REQUEST_RETURNED_TO_DRAFT = 'purchasing_request_returned_to_draft';

    public const REQUEST_CANCELLED = 'purchasing_request_cancelled';

    public const REQUEST_CONVERTED = 'purchasing_request_converted';

    public const ORDER_CREATED = 'purchasing_order_created';

    public const ORDER_UPDATED = 'purchasing_order_updated';

    public const ORDER_LINE_ADDED = 'purchasing_order_line_added';

    public const ORDER_LINE_UPDATED = 'purchasing_order_line_updated';

    public const ORDER_LINE_REMOVED = 'purchasing_order_line_removed';

    public const ORDER_SUBMITTED = 'purchasing_order_submitted';

    public const ORDER_APPROVED = 'purchasing_order_approved';

    public const ORDER_RETURNED_TO_DRAFT = 'purchasing_order_returned_to_draft';

    public const ORDER_REOPENED = 'purchasing_order_reopened';

    public const ORDER_CANCELLED = 'purchasing_order_cancelled';

    public const ORDER_CLOSED = 'purchasing_order_closed';

    public const ORDER_RECEIPT_APPLIED = 'purchasing_order_receipt_applied';

    /**
     * @var list<string>
     */
    public const ALL = [
        self::SUPPLIER_CREATED,
        self::SUPPLIER_UPDATED,
        self::SUPPLIER_DEACTIVATED,
        self::SUPPLIER_ACTIVATED,
        self::SUPPLIER_DELETED,
        self::REQUEST_CREATED,
        self::REQUEST_UPDATED,
        self::REQUEST_LINE_ADDED,
        self::REQUEST_LINE_UPDATED,
        self::REQUEST_LINE_REMOVED,
        self::REQUEST_SUBMITTED,
        self::REQUEST_APPROVED,
        self::REQUEST_REJECTED,
        self::REQUEST_RETURNED_TO_DRAFT,
        self::REQUEST_CANCELLED,
        self::REQUEST_CONVERTED,
        self::ORDER_CREATED,
        self::ORDER_UPDATED,
        self::ORDER_LINE_ADDED,
        self::ORDER_LINE_UPDATED,
        self::ORDER_LINE_REMOVED,
        self::ORDER_SUBMITTED,
        self::ORDER_APPROVED,
        self::ORDER_RETURNED_TO_DRAFT,
        self::ORDER_REOPENED,
        self::ORDER_CANCELLED,
        self::ORDER_CLOSED,
        self::ORDER_RECEIPT_APPLIED,
    ];
}

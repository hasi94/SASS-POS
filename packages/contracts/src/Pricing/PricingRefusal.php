<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * The closed refusal vocabulary of `PricingEngine` (T-029 plan Ruling 2).
 *
 * One case per reason the tax, price-ladder or discount arithmetic can
 * refuse (AC-7, AC-11, AC-15 to AC-17, AC-20, AC-22, AC-23). Nothing here
 * names a table or a column. A refusal is always a named case, never a silent
 * zero and never a raw exception.
 */
enum PricingRefusal: string
{
    /** The named product does not exist or is soft-deleted (the adapter's refusal). */
    case UnknownProduct = 'unknown_product';

    /** The named tax group does not exist (the adapter's refusal). */
    case UnknownTaxGroup = 'unknown_tax_group';

    /** A member tax that applies has no `tax_rates` row effective on the document date (AC-7). */
    case NoEffectiveRate = 'no_effective_rate';

    /** The fixed taxes of an inclusive group alone exceed the gross amount (AC-11.6). */
    case InclusiveAmountTooSmall = 'inclusive_amount_too_small';

    /** No step of the price ladder produced a price (AC-17). */
    case NoPriceResolved = 'no_price_resolved';

    /** The discount id does not resolve to any discount (AC-20.1). */
    case UnknownDiscount = 'unknown_discount';

    /** A bill-scope discount was given to a line, or an item-scope one to a bill (AC-20.2). */
    case DiscountScopeMismatch = 'discount_scope_mismatch';

    /** The discount is switched off (AC-20.3). */
    case Inactive = 'inactive';

    /** The document instant is before `starts_at` (AC-20.3). */
    case NotYetStarted = 'not_yet_started';

    /** The document instant is after `ends_at` (AC-20.3). */
    case Expired = 'expired';

    /** `used_count` has reached `usage_limit` (AC-20.3). */
    case UsageLimitReached = 'usage_limit_reached';

    /** The product's `allow_discount` is false (AC-20.4). */
    case DiscountNotAllowed = 'discount_not_allowed';

    /** `applies_to` names products, categories or brands and the line is in none of them (AC-20.5). */
    case NotApplicable = 'not_applicable';

    /** The line subtotal, or the bill base, is below `min_amount` (AC-20.6). */
    case BelowMinAmount = 'below_min_amount';

    /** `buy_x_get_y` is stored but has no arithmetic (AC-20.7). */
    case UnsupportedDiscountType = 'unsupported_discount_type';

    /** A money, quantity, rate or factor input is not a plain non-negative decimal of the stored precision. */
    case InvalidAmount = 'invalid_amount';

    /** A result would not fit `DECIMAL(18,4)` (plan Ruling 3.5). */
    case OutOfRange = 'out_of_range';
}

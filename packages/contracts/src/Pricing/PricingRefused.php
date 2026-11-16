<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

use RuntimeException;

/**
 * `PricingEngine` refused to price (T-029 plan Ruling 2).
 *
 * **Not constructible without a cause**, the `UnitResolutionRefused` shape:
 * the constructor is private and every way in is a named constructor carrying
 * exactly one {@see PricingRefusal}. The message is operator-facing and may
 * name ids, dates and amounts; it never carries a secret, because there is
 * none to carry here.
 */
final class PricingRefused extends RuntimeException
{
    private function __construct(
        public readonly PricingRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownProduct(string $message): self
    {
        return new self(PricingRefusal::UnknownProduct, $message);
    }

    public static function unknownTaxGroup(string $message): self
    {
        return new self(PricingRefusal::UnknownTaxGroup, $message);
    }

    public static function noEffectiveRate(string $message): self
    {
        return new self(PricingRefusal::NoEffectiveRate, $message);
    }

    public static function inclusiveAmountTooSmall(string $message): self
    {
        return new self(PricingRefusal::InclusiveAmountTooSmall, $message);
    }

    public static function noPriceResolved(string $message): self
    {
        return new self(PricingRefusal::NoPriceResolved, $message);
    }

    public static function unknownDiscount(string $message): self
    {
        return new self(PricingRefusal::UnknownDiscount, $message);
    }

    public static function discountScopeMismatch(string $message): self
    {
        return new self(PricingRefusal::DiscountScopeMismatch, $message);
    }

    public static function inactive(string $message): self
    {
        return new self(PricingRefusal::Inactive, $message);
    }

    public static function notYetStarted(string $message): self
    {
        return new self(PricingRefusal::NotYetStarted, $message);
    }

    public static function expired(string $message): self
    {
        return new self(PricingRefusal::Expired, $message);
    }

    public static function usageLimitReached(string $message): self
    {
        return new self(PricingRefusal::UsageLimitReached, $message);
    }

    public static function discountNotAllowed(string $message): self
    {
        return new self(PricingRefusal::DiscountNotAllowed, $message);
    }

    public static function notApplicable(string $message): self
    {
        return new self(PricingRefusal::NotApplicable, $message);
    }

    public static function belowMinAmount(string $message): self
    {
        return new self(PricingRefusal::BelowMinAmount, $message);
    }

    public static function unsupportedDiscountType(string $message): self
    {
        return new self(PricingRefusal::UnsupportedDiscountType, $message);
    }

    public static function invalidAmount(string $message): self
    {
        return new self(PricingRefusal::InvalidAmount, $message);
    }

    public static function outOfRange(string $message): self
    {
        return new self(PricingRefusal::OutOfRange, $message);
    }
}

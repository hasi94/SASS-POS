<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Exceptions;

use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use RuntimeException;

/**
 * The one exception the pricing CRUD slices throw when they refuse to write
 * (the `CatalogRefused` shape). It is a different class from
 * {@see PricingRefused}, which the calculation
 * engine throws: the two vocabularies share no case and a caller must be able
 * to catch one without swallowing the other.
 *
 * Not constructible without a cause: the constructor is private and every way
 * in is a named constructor carrying exactly one {@see PricingAdminRefusal}.
 * The message is operator-facing and may name ids.
 */
final class PricingAdminRefused extends RuntimeException
{
    private function __construct(
        public readonly PricingAdminRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message): self
    {
        return new self(PricingAdminRefusal::Validation, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(PricingAdminRefusal::NotFound, $message);
    }

    public static function duplicate(string $message): self
    {
        return new self(PricingAdminRefusal::Duplicate, $message);
    }

    public static function inUse(string $message): self
    {
        return new self(PricingAdminRefusal::InUse, $message);
    }

    public static function overlap(string $message): self
    {
        return new self(PricingAdminRefusal::Overlap, $message);
    }

    public static function maintained(string $message): self
    {
        return new self(PricingAdminRefusal::Maintained, $message);
    }

    public static function unresolvableUnit(string $message): self
    {
        return new self(PricingAdminRefusal::UnresolvableUnit, $message);
    }

    public static function missingTarget(string $message): self
    {
        return new self(PricingAdminRefusal::MissingTarget, $message);
    }

    public static function fixedCannotCompound(string $message): self
    {
        return new self(PricingAdminRefusal::FixedCannotCompound, $message);
    }
}

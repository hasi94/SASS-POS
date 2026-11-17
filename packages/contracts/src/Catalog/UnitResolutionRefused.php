<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Catalog;

use RuntimeException;

/**
 * `UnitResolver` refused to resolve or snapshot (T-027 plan Ruling 2).
 *
 * **Not constructible without a cause**, the `OrganizationRefused`/
 * `AccessRefused` shape: the constructor is private and every way in is a
 * named constructor carrying exactly one {@see UnitRefusal}. The message is
 * operator-facing and may name ids, units and factors; it never carries a
 * secret, because there is none to carry here.
 */
final class UnitResolutionRefused extends RuntimeException
{
    private function __construct(
        public readonly UnitRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownProduct(string $message): self
    {
        return new self(UnitRefusal::UnknownProduct, $message);
    }

    public static function unknownUnit(string $message): self
    {
        return new self(UnitRefusal::UnknownUnit, $message);
    }

    public static function crossDimension(string $message): self
    {
        return new self(UnitRefusal::CrossDimension, $message);
    }

    public static function noConversionPath(string $message): self
    {
        return new self(UnitRefusal::NoConversionPath, $message);
    }

    public static function factorBelowOne(string $message): self
    {
        return new self(UnitRefusal::FactorBelowOne, $message);
    }

    public static function inexactFactor(string $message): self
    {
        return new self(UnitRefusal::InexactFactor, $message);
    }

    public static function factorOutOfRange(string $message): self
    {
        return new self(UnitRefusal::FactorOutOfRange, $message);
    }

    public static function invalidQuantity(string $message): self
    {
        return new self(UnitRefusal::InvalidQuantity, $message);
    }

    public static function quantityOutOfRange(string $message): self
    {
        return new self(UnitRefusal::QuantityOutOfRange, $message);
    }

    public static function fractionalBaseQuantity(string $message): self
    {
        return new self(UnitRefusal::FractionalBaseQuantity, $message);
    }

    public static function inheritedUnitMismatch(string $message): self
    {
        return new self(UnitRefusal::InheritedUnitMismatch, $message);
    }
}

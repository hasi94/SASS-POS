<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Exceptions;

use Flexipos\Catalog\Support\CatalogRefusal;
use RuntimeException;

/**
 * The one exception this package throws when it refuses to do something
 * (T-027 plan section 3, the `OrganizationRefused`/`AccessRefused` shape).
 *
 * **Not constructible without a cause.** The constructor is private and
 * every way in is a named constructor carrying exactly one
 * {@see CatalogRefusal}, so there is no "refused" that means nothing more.
 * The message is operator-facing and may name ids; it never carries a
 * secret, because there is none to carry in this package.
 */
final class CatalogRefused extends RuntimeException
{
    private function __construct(
        public readonly CatalogRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message): self
    {
        return new self(CatalogRefusal::Validation, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(CatalogRefusal::NotFound, $message);
    }

    public static function duplicate(string $message): self
    {
        return new self(CatalogRefusal::Duplicate, $message);
    }

    public static function inUse(string $message): self
    {
        return new self(CatalogRefusal::InUse, $message);
    }

    public static function immutable(string $message): self
    {
        return new self(CatalogRefusal::Immutable, $message);
    }

    public static function cycle(string $message): self
    {
        return new self(CatalogRefusal::Cycle, $message);
    }

    public static function tooDeep(string $message): self
    {
        return new self(CatalogRefusal::TooDeep, $message);
    }

    public static function dimensionMismatch(string $message): self
    {
        return new self(CatalogRefusal::DimensionMismatch, $message);
    }

    public static function unknownTaxGroup(string $message): self
    {
        return new self(CatalogRefusal::UnknownTaxGroup, $message);
    }

    public static function inactiveTaxGroup(string $message): self
    {
        return new self(CatalogRefusal::InactiveTaxGroup, $message);
    }

    public static function noDefaultTaxGroup(string $message): self
    {
        return new self(CatalogRefusal::NoDefaultTaxGroup, $message);
    }
}

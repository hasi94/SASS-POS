<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Illuminate\Support\Str;

/**
 * What an identifier is, and the one fold it takes on entry (T-014 AC-61).
 *
 * Every id here is a 26-character ULID stored upper-case: under `ascii_bin`,
 * two spellings differing only in case are two different strings, so every
 * boundary folds first and compares, stores and uses the folded value.
 */
final class Identifier
{
    public const LENGTH = 26;

    /**
     * @throws PricingAdminRefused validation
     */
    public static function fold(mixed $submitted, string $what = 'id'): string
    {
        if (! is_string($submitted) || mb_strlen($submitted) !== self::LENGTH || ! Str::isUlid($submitted)) {
            throw PricingAdminRefused::validation("The {$what} is not a ".self::LENGTH.'-character ULID.');
        }

        return strtoupper($submitted);
    }

    /**
     * @throws PricingAdminRefused validation
     */
    public static function foldNullable(mixed $submitted, string $what = 'id'): ?string
    {
        if ($submitted === null) {
            return null;
        }

        return self::fold($submitted, $what);
    }
}

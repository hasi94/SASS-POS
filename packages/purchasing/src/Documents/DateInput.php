<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use DateTimeImmutable;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/**
 * A calendar date as the caller submits it (T-034 AC-20, AC-41, AC-91).
 *
 * Exactly `Y-m-d` and a real day: `2026-02-30` is not a date (the parser would
 * roll it into March, so the formatted value is compared back), and a date with a
 * time, a different separator or an int is refused. Pure: it compares strings, so
 * "earlier than today" is a plain comparison of two `Y-m-d` values.
 */
final class DateInput
{
    /**
     * Null when absent or null.
     *
     * @throws PurchasingRefused validation
     */
    public static function optional(mixed $submitted, string $name): ?string
    {
        if ($submitted === null) {
            return null;
        }

        if (! is_string($submitted) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $submitted) !== 1) {
            throw PurchasingRefused::validation("The {$name} must be a date written Y-m-d.");
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $submitted);

        if ($parsed === false || $parsed->format('Y-m-d') !== $submitted) {
            throw PurchasingRefused::validation("The {$name} is not a real calendar date.");
        }

        return $submitted;
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Hr\Profiles;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;

/**
 * The date relationships of a profile (T-038 AC-46, D-28), pure.
 *
 * Dates here are already-validated `Y-m-d` strings, so a plain string
 * comparison is a date comparison. **The order rules run on the merged
 * result of an update**, never the request alone: a change to `joining_date`
 * that breaks an existing later date must be refused even though the request
 * names only the one field (mutation M4).
 */
final class DateRules
{
    /**
     * The date fields that must not precede `joining_date`.
     *
     * @var list<string>
     */
    public const AFTER_JOINING = ['confirmation_date', 'probation_end_date', 'resignation_date', 'last_working_date'];

    /**
     * Refuse the first pair that is out of order, naming both fields.
     *
     * A pair is checked only when both sides are present (non-null), so a
     * partial array is allowed; equal dates are in order.
     *
     * @param  array<string, mixed>  $dates  field => `Y-m-d` string or null
     *
     * @throws HrRefused date_order
     */
    public static function assertOrder(array $dates): void
    {
        $joining = self::dateOf($dates, 'joining_date');

        if ($joining !== null) {
            foreach (self::AFTER_JOINING as $field) {
                $date = self::dateOf($dates, $field);

                if ($date !== null && $date < $joining) {
                    throw self::disorder('joining_date', $field);
                }
            }
        }

        $resignation = self::dateOf($dates, 'resignation_date');
        $last = self::dateOf($dates, 'last_working_date');

        if ($resignation !== null && $last !== null && $last < $resignation) {
            throw self::disorder('resignation_date', 'last_working_date');
        }
    }

    /**
     * The date of birth may not be after today (the injectable clock's UTC
     * date, passed in).
     *
     * @throws HrRefused validation
     */
    public static function assertNotFuture(string $dob, string $today): void
    {
        if ($dob > $today) {
            throw HrRefused::validation('dob', 'The dob must not be in the future.');
        }
    }

    /**
     * @param  array<string, mixed>  $dates
     */
    private static function dateOf(array $dates, string $field): ?string
    {
        $value = $dates[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    private static function disorder(string $earlier, string $later): HrRefused
    {
        return HrRefused::because(
            HrRefusal::DateOrder,
            "The {$later} must be on or after the {$earlier}.",
            $later,
            ['earlier' => $earlier, 'later' => $later],
        );
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Hr\Support;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;

/**
 * The page size of every HR list (T-038 AC-26, AC-104, D-25).
 *
 * **Refused, never clamped**: a clamp hides a client defect. Null means the
 * default; an integer from 1 to the maximum is taken as asked; anything else,
 * including `0`, `201` and the string `"50"`, is `page_size_invalid`. Both
 * figures arrive as arguments (from `config/hr.php`), so no number is written
 * here.
 */
final class PageSize
{
    /**
     * @throws HrRefused page_size_invalid
     */
    public static function of(mixed $asked, int $default, int $max): int
    {
        if ($asked === null) {
            return $default;
        }

        if (! is_int($asked) || $asked < 1 || $asked > $max) {
            throw HrRefused::because(
                HrRefusal::PageSizeInvalid,
                "The page size must be an integer from 1 to {$max}.",
                'page_size',
            );
        }

        return $asked;
    }
}

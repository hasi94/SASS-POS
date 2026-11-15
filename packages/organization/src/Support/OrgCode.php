<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;

/**
 * The code rule shared by branches, locations and terminals (T-014 D-13,
 * AC-11).
 *
 * Trimmed, folded to upper case, one to ten characters from `A-Z`, `0-9` and
 * `-`. The fold is applied before the check, so `a1-b` is `A1-B` and is
 * accepted. Uniqueness is the tables' and is checked by each slice against
 * soft-deleted rows too.
 */
final class OrgCode
{
    public const MAX = 10;

    /**
     * @throws OrganizationRefused validation
     */
    public static function normalise(mixed $submitted, string $what = 'code'): string
    {
        if (! is_string($submitted)) {
            throw OrganizationRefused::validation("The {$what} must be submitted as a string.");
        }

        $code = strtoupper(trim($submitted));

        if (preg_match('/\A[A-Z0-9-]{1,'.self::MAX.'}\z/', $code) !== 1) {
            throw OrganizationRefused::validation(
                "The {$what} must be 1 to ".self::MAX.' characters from A-Z, 0-9 and hyphen.'
            );
        }

        return $code;
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Illuminate\Support\Str;

/**
 * What an identifier is, and the one fold it takes on entry (T-014 AC-61).
 *
 * Every id here is a 26-character ULID stored upper-case. Under `ascii_bin`
 * a lower- and an upper-case spelling are two different strings, so every
 * boundary - a path, a body field, a scope - folds first and compares,
 * stores and uses the folded value. This is the one fold in this package; a
 * second spelling of the rule is a second place for a case to slip through.
 * A string that is not a 26-character ULID in either case is `validation`.
 */
final class Identifier
{
    public const LENGTH = 26;

    /**
     * @throws OrganizationRefused validation
     */
    public static function fold(mixed $submitted, string $what = 'id'): string
    {
        if (! is_string($submitted) || mb_strlen($submitted) !== self::LENGTH || ! Str::isUlid($submitted)) {
            throw OrganizationRefused::validation("The {$what} is not a ".self::LENGTH.'-character ULID.');
        }

        return strtoupper($submitted);
    }
}

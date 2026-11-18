<?php

declare(strict_types=1);

namespace Flexipos\Hr\Support;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;

/**
 * Which keys a request body may carry (T-038 AC-16, AC-22, AC-42, D-21).
 *
 * A key outside the allowed set, or one with its own operation, refuses the
 * **whole** request and every offender is named in the one refusal: ignoring
 * an unknown key lets a caller believe something was set. The refusal names
 * fields only and never a value. A key that is not a plain field name (a
 * caller can put anything in a key) is reported as `(invalid field name)`
 * rather than echoed, so the message cannot carry submitted text.
 */
final class Fields
{
    /**
     * The identity fields access owns (AC-42): on a profile request they are
     * refused `not_updatable_here`, because they change only through
     * access's own operations.
     *
     * @var list<string>
     */
    public const IDENTITY = [
        'code', 'first_name', 'last_name', 'display_name', 'status', 'user_id', 'pin_hash', 'perm_version',
    ];

    /**
     * @param  array<array-key, mixed>  $body
     * @param  list<string>  $allowed  the keys the operation accepts
     * @param  list<string>  $notUpdatable  keys that exist but belong elsewhere: refused `not_updatable_here`
     *
     * @throws HrRefused not_updatable_here when any offender is in `$notUpdatable`, otherwise validation; both name every offender
     */
    public static function only(array $body, array $allowed, array $notUpdatable = []): void
    {
        $offending = [];
        $elsewhere = false;

        foreach (array_keys($body) as $key) {
            $key = (string) $key;

            if (in_array($key, $allowed, true)) {
                continue;
            }

            $offending[] = self::nameOf($key);

            if (in_array($key, $notUpdatable, true)) {
                $elsewhere = true;
            }
        }

        if ($offending === []) {
            return;
        }

        throw HrRefused::because(
            $elsewhere ? HrRefusal::NotUpdatableHere : HrRefusal::Validation,
            'These fields are not accepted here: '.implode(', ', $offending).'.',
            null,
            ['fields' => $offending],
        );
    }

    private static function nameOf(string $key): string
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_.]{0,63}\z/', $key) === 1 ? $key : '(invalid field name)';
    }
}

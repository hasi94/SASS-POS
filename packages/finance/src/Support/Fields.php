<?php

declare(strict_types=1);

namespace Flexipos\Finance\Support;

use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;

/**
 * Which keys a request body may carry (T-032 AC-8, AC-36, D-10).
 *
 * A key outside the allowed set, or one in the forbidden set, refuses the
 * **whole** request and every offender is named in the one refusal: ignoring
 * an unknown key lets a caller believe something was set.
 */
final class Fields
{
    /**
     * Keys no account request may carry (AC-8, D-9). `currency` is handled by
     * the caller: it is allowed as null and forbidden otherwise.
     *
     * @var list<string>
     */
    public const ACCOUNT_FORBIDDEN = [
        'id', 'is_system', 'path', 'depth', 'deleted_at', 'created_by', 'updated_by',
        'opening_balance', 'opening_balance_date',
    ];

    /**
     * @param  array<array-key, mixed>  $body
     * @param  list<string>  $allowed
     * @param  list<string>  $forbidden
     *
     * @throws FinanceRefused validation, naming every offending key
     */
    public static function only(array $body, array $allowed, array $forbidden = []): void
    {
        $offending = [];

        foreach (array_keys($body) as $key) {
            $key = (string) $key;

            if (in_array($key, $forbidden, true) || ! in_array($key, $allowed, true)) {
                $offending[] = $key;
            }
        }

        if ($offending !== []) {
            throw FinanceRefused::because(
                FinanceRefusal::Validation,
                'These fields are not accepted here: '.implode(', ', $offending).'.',
                null,
                ['fields' => $offending],
            );
        }
    }
}

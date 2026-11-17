<?php

declare(strict_types=1);

namespace Flexipos\Finance\Accounts;

use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;

/**
 * The refusals more than one account slice raises, built in one place so
 * every door gives the same reason, field and context (T-032 AC-20, AC-23,
 * AC-24).
 */
final class AccountRefusals
{
    /**
     * The target is absent or soft-deleted (AC-20). The message does not say
     * which: a soft-deleted account's existence is not for a caller to learn
     * beyond the code reservation (spec NFR, Security).
     */
    public static function notLive(string $id): FinanceRefused
    {
        return FinanceRefused::because(
            FinanceRefusal::AccountNotLive,
            "The account [{$id}] does not exist or has been deleted.",
            'account_id',
            ['account' => $id],
        );
    }

    /**
     * Records still name the account (AC-23, AC-24): the kinds and counts
     * are the caller's way to find them.
     *
     * @param  array<string, array{live: int, deleted: int}>  $referrers
     */
    public static function inUse(array $referrers): FinanceRefused
    {
        $named = [];

        foreach ($referrers as $kind => $counts) {
            $named[] = "{$kind} (live {$counts['live']}, deleted {$counts['deleted']})";
        }

        return FinanceRefused::because(
            FinanceRefusal::InUse,
            'The account is still referenced by: '.implode(', ', $named).'.',
            'account_id',
            ['referrers' => $referrers],
        );
    }
}

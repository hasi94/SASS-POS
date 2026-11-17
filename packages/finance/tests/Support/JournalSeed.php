<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Support;

use Illuminate\Database\Connection;

/**
 * Rows in a fixture tenant's journal tables, written directly (T-033 plan
 * step A2, the `FinanceSeed` shape). It builds on {@see FinanceSeed}: the
 * year, the accounts and the actors come from there, and this class adds the
 * one thing `FinanceSeed::clearSeed()` cannot do without being edited, which
 * is to clear journal rows first.
 *
 * **Why `clear()` exists.** `journal_lines.account_id` and
 * `journal_entries.period_id` are RESTRICT keys, so a test that wrote an
 * entry cannot empty the chart or the calendar until the entries are gone,
 * and `fiscal_years.retained_earnings_entry_id` (a key once `003304` ran)
 * points into `journal_entries`. A test that posts calls {@see self::clear()}
 * instead of `FinanceSeed::clearSeed()`.
 *
 * Direct inserts only, and only in tests: the application's one writer of
 * journal rows is `Flexipos\Finance\Journal\JournalWriter` (T-033 AC-5).
 */
final class JournalSeed
{
    public static function connection(): Connection
    {
        return FinanceSeed::connection();
    }

    /**
     * Delete every journal line and entry, release the year-end entry
     * reference, then empty the chart and the calendar the way
     * {@see FinanceSeed::clearSeed()} does.
     */
    public static function clear(): void
    {
        $connection = self::connection();

        $connection->table('fiscal_years')->update(['retained_earnings_entry_id' => null]);
        $connection->table('journal_entries')->update(['reversal_of_id' => null, 'reversed_by_id' => null]);
        $connection->table('journal_lines')->delete();
        $connection->table('journal_entries')->delete();

        FinanceSeed::clearSeed();
    }
}

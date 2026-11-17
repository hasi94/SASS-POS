<?php

declare(strict_types=1);

namespace Flexipos\Finance\Periods;

use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Support\TenantConnection;
use Illuminate\Database\Query\Builder;

/**
 * Every read and write of `fiscal_years` and `fiscal_periods` (T-032 plan
 * Ruling 6, section 0.3 W-4; the {@see AccountRows}
 * construction).
 *
 * **A method's name states its lock mode.** A write handler never builds a
 * query on these tables and never calls a terminal read on one: it calls a
 * method here. `*ForUpdate` and `*SharedLock` are the locking reads of the
 * write path, and the `*Unlocked` methods are the only plain reads (the
 * read slices, and the immutable coordinates of a period, which no write
 * changes: its `start_date`, `end_date`, `sequence` and year).
 *
 * **Lock order, global** (Ruling 6): `fiscal_years` before `fiscal_periods`,
 * each ascending by `start_date`. The methods that lock several rows return
 * them in that order.
 *
 * **Not final, on purpose**: a test replaces `insertYear` and `insertPeriod`
 * to inject a fault part-way through a create (AC-31) and to slip a gapped
 * year in between the pre-check and the verification (AC-35), because the
 * stand-in has no second connection to race with.
 */
class PeriodRows
{
    public function __construct(private readonly TenantConnection $tenant) {}

    // -- Years: locking reads -----------------------------------------------

    /**
     * The latest year by `start_date`, `FOR UPDATE`: a new year is created
     * contiguous to it, and two creates queue behind this lock (AC-33).
     */
    public function latestYearForUpdate(): ?object
    {
        return $this->years()->orderByDesc('start_date')->limit(1)->lockForUpdate()->first();
    }

    public function yearForUpdate(string $id): ?object
    {
        return $this->years()->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * Every year starting on or after `$startDate`, ascending, `FOR UPDATE`
     * (a year reopen locks its own year and every later one, AC-46).
     *
     * @return list<object>
     */
    public function yearsFromForUpdate(string $startDate): array
    {
        return array_values($this->years()->where('start_date', '>=', $startDate)
            ->orderBy('start_date')->lockForUpdate()->get()->all());
    }

    /**
     * The whole year chain, ascending, locked: the post-insert verification
     * that the years are contiguous (AC-35).
     *
     * @return list<object>
     */
    public function yearChainForUpdate(): array
    {
        return array_values($this->years()->orderBy('start_date')->lockForUpdate()->get()->all());
    }

    public function yearByStartSharedLock(string $startDate): ?object
    {
        return $this->years()->where('start_date', $startDate)->sharedLock()->first();
    }

    // -- Periods: locking reads ---------------------------------------------

    public function periodForUpdate(string $id): ?object
    {
        return $this->periods()->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * A year's periods, by sequence (which is by `start_date`), `FOR UPDATE`.
     *
     * @return list<object>
     */
    public function periodsOfYearForUpdate(string $yearId): array
    {
        return array_values($this->periods()->where('fiscal_year_id', $yearId)
            ->orderBy('start_date')->lockForUpdate()->get()->all());
    }

    /**
     * Periods whose `start_date` is in `[$from, $to]`, ascending, `FOR UPDATE`:
     * the target of a transition and its two neighbours (Ruling 6).
     *
     * @return list<object>
     */
    public function periodsBetweenForUpdate(string $from, string $to): array
    {
        return array_values($this->periods()->whereBetween('start_date', [$from, $to])
            ->orderBy('start_date')->lockForUpdate()->get()->all());
    }

    /**
     * The one period covering a date, under a **shared** lock: the gate's
     * only read (AC-47, AC-50).
     *
     * The read picks the single latest period starting on or before the date
     * (one index entry, so one row is locked) and checks `end_date >= date`
     * here: a range predicate on both columns could lock every row it scans.
     * A date in a gap, or past the last period, finds no cover and returns null.
     */
    public function periodCoveringSharedLock(string $date): ?object
    {
        $row = $this->periods()->where('start_date', '<=', $date)
            ->orderByDesc('start_date')->limit(1)->sharedLock()->first();

        return $this->coveringOrNull($row, $date);
    }

    // -- Writes -------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $row
     */
    public function insertYear(array $row): void
    {
        $this->years()->insert($row);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function insertPeriod(array $row): void
    {
        $this->periods()->insert($row);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateYear(string $id, array $changes): void
    {
        $this->years()->where('id', $id)->update($changes);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updatePeriod(string $id, array $changes): void
    {
        $this->periods()->where('id', $id)->update($changes);
    }

    public function deletePeriodsOfYear(string $yearId): void
    {
        $this->periods()->where('fiscal_year_id', $yearId)->delete();
    }

    public function deleteYear(string $id): void
    {
        $this->years()->where('id', $id)->delete();
    }

    // -- Plain reads: the read slices, and immutable coordinates -------------

    public function yearByIdUnlocked(string $id): ?object
    {
        return $this->years()->where('id', $id)->first();
    }

    public function periodByIdUnlocked(string $id): ?object
    {
        return $this->periods()->where('id', $id)->first();
    }

    /**
     * The latest `start_date` of any period before `$start`, or null: the
     * lower bound of a transition's lock range. A period's coordinates never
     * change, so reading them unlocked is safe; the locked read that follows
     * is what the decision rests on.
     */
    public function previousPeriodStartUnlocked(string $start): ?string
    {
        $value = $this->periods()->where('start_date', '<', $start)->max('start_date');

        return $value === null ? null : (string) $value;
    }

    public function nextPeriodStartUnlocked(string $start): ?string
    {
        $value = $this->periods()->where('start_date', '>', $start)->min('start_date');

        return $value === null ? null : (string) $value;
    }

    /**
     * One keyset page of years, ascending by `start_date`, after the cursor.
     *
     * @return list<object>
     */
    public function yearsPageUnlocked(int $limit, ?string $afterStart): array
    {
        $query = $this->years();

        if ($afterStart !== null) {
            $query->where('start_date', '>', $afterStart);
        }

        return array_values($query->orderBy('start_date')->limit($limit)->get()->all());
    }

    /**
     * @return list<object>
     */
    public function periodsOfYearUnlocked(string $yearId): array
    {
        return array_values($this->periods()->where('fiscal_year_id', $yearId)->orderBy('sequence')->get()->all());
    }

    /**
     * The same single indexed read the gate makes, without a lock.
     */
    public function periodCoveringUnlocked(string $date): ?object
    {
        $row = $this->periods()->where('start_date', '<=', $date)->orderByDesc('start_date')->limit(1)->first();

        return $this->coveringOrNull($row, $date);
    }

    private function coveringOrNull(?object $row, string $date): ?object
    {
        return $row !== null && (string) $row->end_date >= $date ? $row : null;
    }

    private function years(): Builder
    {
        return $this->tenant->get()->table('fiscal_years');
    }

    private function periods(): Builder
    {
        return $this->tenant->get()->table('fiscal_periods');
    }
}

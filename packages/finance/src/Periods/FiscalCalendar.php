<?php

declare(strict_types=1);

namespace Flexipos\Finance\Periods;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Support\Input;

/**
 * The shape of a fiscal year, pure (T-032 plan Ruling 7; AC-31 to AC-33).
 *
 * A year is twelve calendar months starting on the first of a month. Nothing
 * here reads the clock or the database: the seed migration and the API both
 * derive their periods from this one calculation, and a test holds the
 * migration's inline copy equal to it.
 */
final class FiscalCalendar
{
    /** The earliest and latest calendar year a fiscal year may start in (AC-32). */
    public const FIRST_YEAR = 2000;

    public const LAST_YEAR = 2100;

    /**
     * The last day of the year starting on `$start`: twelve months later, less
     * a day (AC-31).
     *
     * @throws FinanceRefused invalid_date
     */
    public static function yearEnd(string $start): string
    {
        return self::shift(self::start($start), 'P12M');
    }

    /**
     * The twelve periods of the year starting on `$start`: sequence 1 to 12,
     * named `P01` to `P12`, each exactly one calendar month, tiling the year
     * with no gap or overlap (AC-31).
     *
     * @return list<array{sequence: int, name: string, start: string, end: string}>
     *
     * @throws FinanceRefused invalid_date
     */
    public static function periodsFor(string $start): array
    {
        $first = self::start($start);
        $periods = [];

        for ($index = 0; $index < 12; $index++) {
            $from = $first->add(new DateInterval('P'.$index.'M'));

            $periods[] = [
                'sequence' => $index + 1,
                'name' => sprintf('P%02d', $index + 1),
                'start' => $from->format('Y-m-d'),
                'end' => self::shift($from, 'P1M'),
            ];
        }

        return $periods;
    }

    /**
     * A new year must begin the day after the latest existing year ends; the
     * first year in a tenant is free (AC-33).
     *
     * @throws FinanceRefused fiscal_year_not_contiguous
     */
    public static function contiguous(?string $latestEnd, string $start): void
    {
        if ($latestEnd === null) {
            return;
        }

        $expected = self::utc($latestEnd)->add(new DateInterval('P1D'))->format('Y-m-d');

        if ($start !== $expected) {
            throw FinanceRefused::because(
                FinanceRefusal::FiscalYearNotContiguous,
                "A new fiscal year must start on {$expected}, the day after the latest one ends.",
                'start_date',
                ['expected_start' => $expected],
            );
        }
    }

    /**
     * A real strict date, the first of a month, in 2000 to 2100 (AC-32).
     */
    private static function start(string $start): DateTimeImmutable
    {
        $date = Input::date($start, 'start_date');
        $year = (int) substr($date, 0, 4);

        if (substr($date, 8, 2) !== '01' || $year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
            throw FinanceRefused::because(
                FinanceRefusal::InvalidDate,
                'A fiscal year starts on the first day of a month, in '.self::FIRST_YEAR.' to '.self::LAST_YEAR.'.',
                'start_date',
            );
        }

        return self::utc($date);
    }

    /**
     * The day before `$from` plus `$interval`: the last day of the span.
     */
    private static function shift(DateTimeImmutable $from, string $interval): string
    {
        return $from->add(new DateInterval($interval))->sub(new DateInterval('P1D'))->format('Y-m-d');
    }

    private static function utc(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
    }
}

<?php

namespace Flexipos\Finance\Tests\Periods;

use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\FiscalCalendar;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| FiscalCalendar (T-032 plan step 7; AC-31 to AC-33)
|--------------------------------------------------------------------------
*/

function financeCalendarRefusal(callable $run): FinanceRefused
{
    try {
        $run();
    } catch (FinanceRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('The calendar accepted what it should have refused.');
}

it('tiles a calendar year into twelve periods ending on 31 December', function () {
    $periods = FiscalCalendar::periodsFor('2026-01-01');

    expect($periods)->toHaveCount(12)
        ->and($periods[0])->toBe(['sequence' => 1, 'name' => 'P01', 'start' => '2026-01-01', 'end' => '2026-01-31'])
        ->and($periods[1])->toBe(['sequence' => 2, 'name' => 'P02', 'start' => '2026-02-01', 'end' => '2026-02-28'])
        ->and($periods[11])->toBe(['sequence' => 12, 'name' => 'P12', 'start' => '2026-12-01', 'end' => '2026-12-31'])
        ->and(FiscalCalendar::yearEnd('2026-01-01'))->toBe('2026-12-31');
});

it('ends a year that starts in March on the last day of February, leap or not', function () {
    expect(FiscalCalendar::yearEnd('2026-03-01'))->toBe('2027-02-28')
        ->and(FiscalCalendar::yearEnd('2027-03-01'))->toBe('2028-02-29')
        ->and(FiscalCalendar::periodsFor('2027-03-01')[11]['end'])->toBe('2028-02-29');
});

it('gives a leap february 29 days', function () {
    expect(FiscalCalendar::periodsFor('2028-01-01')[1]['end'])->toBe('2028-02-29');
});

it('starts every period the day after the previous one ends, with no gap or overlap', function (string $start) {
    $periods = FiscalCalendar::periodsFor($start);

    expect($periods[0]['start'])->toBe($start)
        ->and($periods[11]['end'])->toBe(FiscalCalendar::yearEnd($start));

    for ($i = 1; $i < 12; $i++) {
        $dayAfter = date('Y-m-d', strtotime($periods[$i - 1]['end'].' +1 day UTC'));

        expect($periods[$i]['start'])->toBe($dayAfter)
            ->and($periods[$i]['sequence'])->toBe($i + 1)
            ->and($periods[$i]['name'])->toBe(sprintf('P%02d', $i + 1));
    }
})->with([
    'january' => ['2026-01-01'],
    'march' => ['2026-03-01'],
    'leap march' => ['2027-03-01'],
    'december' => ['2026-12-01'],
    'earliest allowed' => ['2000-01-01'],
    'latest allowed' => ['2100-12-01'],
]);

it('refuses a start that is not the first of a month or is outside 2000 to 2100 (AC-32)', function (string $start) {
    $refused = financeCalendarRefusal(fn () => FiscalCalendar::periodsFor($start));

    expect($refused->reason)->toBe(FinanceRefusal::InvalidDate)
        ->and($refused->field)->toBe('start_date')
        ->and(financeCalendarRefusal(fn () => FiscalCalendar::yearEnd($start))->reason)->toBe(FinanceRefusal::InvalidDate);
})->with([
    'second of the month' => ['2026-01-02'],
    'last of the month' => ['2026-01-31'],
    'year 1999' => ['1999-12-01'],
    'year 2101' => ['2101-01-01'],
    'not a date' => ['2026-02-30'],
    'not strict' => ['2026-1-1'],
]);

it('requires the next year to start the day after the latest one ends (AC-33)', function () {
    FiscalCalendar::contiguous('2026-12-31', '2027-01-01');
    FiscalCalendar::contiguous('2027-02-28', '2027-03-01');
    FiscalCalendar::contiguous(null, '2026-07-01');

    foreach (['2026-12-31', '2027-01-02', '2027-02-01', '2026-01-01'] as $start) {
        $refused = financeCalendarRefusal(fn () => FiscalCalendar::contiguous('2026-12-31', $start));

        expect($refused->reason)->toBe(FinanceRefusal::FiscalYearNotContiguous)
            ->and($refused->context['expected_start'])->toBe('2027-01-01');
    }
});

it('lets the first year in a tenant start on any first of a month', function () {
    FiscalCalendar::contiguous(null, '2026-04-01');

    expect(FiscalCalendar::periodsFor('2026-04-01')[0]['start'])->toBe('2026-04-01');
});

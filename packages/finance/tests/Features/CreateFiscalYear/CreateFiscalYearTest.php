<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\CreateFiscalYear;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYear;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYearHandler;
use Flexipos\Finance\Periods\FiscalCalendar;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 13 - creating a fiscal year (AC-31 to AC-35)
|--------------------------------------------------------------------------
|
| The stand-in has no second connection, so the two races (AC-35) are
| proven the way the account backstops were: by a test double that stands in
| for the other writer (a blind pre-check, a gapped year slipped in between
| the pre-check and the verification), and the real two-connection proof is
| the engine test's. Every refusal writes nothing, no year, no period and no
| audit row.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/finance-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $fields
 */
function createFiscalYearAs(?string $actorId, array $fields): string
{
    /** @var CreateFiscalYearHandler $handler */
    $handler = app(CreateFiscalYearHandler::class);

    return $handler->handle(new CreateFiscalYear($fields, $actorId))->fiscalYearId;
}

/**
 * @return array{years: int, periods: int, audit: int}
 */
function calendarCounts(): array
{
    return [
        'years' => FinanceSeed::connection()->table('fiscal_years')->count(),
        'periods' => FinanceSeed::connection()->table('fiscal_periods')->count(),
        'audit' => FinanceSeed::connection()->table('audit_logs')->count(),
    ];
}

it('creates one year and twelve tiling monthly periods in one transaction (AC-31)', function () {
    $actor = FinanceSeed::admin();

    $id = createFiscalYearAs($actor, ['name' => 'FY2026', 'start_date' => '2026-01-01']);

    $year = FinanceSeed::connection()->table('fiscal_years')->where('id', $id)->first();
    $periods = FinanceSeed::connection()->table('fiscal_periods')->where('fiscal_year_id', $id)->orderBy('sequence')->get();

    expect($year)->toMatchArray([
        'id' => $id, 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'status' => 'open', 'closed_at' => null, 'closed_by' => null, 'retained_earnings_entry_id' => null,
        'created_at' => '2026-11-10 10:00:00.000', 'updated_at' => '2026-11-10 10:00:00.000',
    ])
        ->and($periods)->toHaveCount(12);

    $expected = FiscalCalendar::periodsFor('2026-01-01');

    foreach ($periods as $index => $period) {
        expect([$period->sequence, $period->name, $period->start_date, $period->end_date, $period->status])
            ->toBe([$expected[$index]['sequence'], 'FY2026 '.$expected[$index]['name'], $expected[$index]['start'], $expected[$index]['end'], 'open']);
    }

    expect($periods[1]->end_date)->toBe('2026-02-28')->and($periods[11]->end_date)->toBe('2026-12-31');

    $audit = FinanceSeed::onlyAuditRow();
    expect($audit)->toMatchArray(['action' => 'fiscal_year_created', 'subject_id' => $id, 'before' => null])
        ->and($audit['after']['name'])->toBe('FY2026');
});

it('gives a leap year its 29th of February and a mid-year start its own tiling (AC-31)', function () {
    $actor = FinanceSeed::admin();

    $id = createFiscalYearAs($actor, ['name' => 'FY2024-25', 'start_date' => '2024-07-01']);

    $periods = FinanceSeed::connection()->table('fiscal_periods')->where('fiscal_year_id', $id)->orderBy('sequence')->get();

    expect(FinanceSeed::connection()->table('fiscal_years')->where('id', $id)->value('end_date'))->toBe('2025-06-30')
        ->and($periods[7]->start_date)->toBe('2025-02-01')
        ->and($periods[7]->end_date)->toBe('2025-02-28');

    FinanceSeed::connection()->table('fiscal_periods')->delete();
    FinanceSeed::connection()->table('fiscal_years')->delete();

    $leap = createFiscalYearAs($actor, ['name' => 'FY2024', 'start_date' => '2024-01-01']);

    expect(FinanceSeed::connection()->table('fiscal_periods')->where('fiscal_year_id', $leap)->where('sequence', 2)->value('end_date'))
        ->toBe('2024-02-29');
});

it('leaves no year behind when a fault hits the seventh period (AC-31, atomicity)', function () {
    $actor = FinanceSeed::admin();

    app()->instance(PeriodRows::class, new class(app(TenantConnection::class)) extends PeriodRows
    {
        public function insertPeriod(array $row): void
        {
            if ($row['sequence'] === 7) {
                throw new RuntimeException('injected fault at period 7');
            }

            parent::insertPeriod($row);
        }
    });

    expect(fn () => createFiscalYearAs($actor, ['name' => 'FY2026', 'start_date' => '2026-01-01']))
        ->toThrow(RuntimeException::class, 'injected fault at period 7');

    expect(calendarCounts())->toBe(['years' => 0, 'periods' => 0, 'audit' => 0]);
});

it('requires the next year to start the day after the latest ends (AC-33)', function () {
    $actor = FinanceSeed::admin();
    createFiscalYearAs($actor, ['name' => 'FY2026', 'start_date' => '2026-01-01']);
    $before = calendarCounts();

    foreach (['2026-12-01' => 'overlapping', '2025-01-01' => 'earlier', '2027-02-01' => 'gapped', '2026-01-01' => 'same'] as $start => $why) {
        $refused = FinanceRefusals::expect(
            FinanceRefusal::FiscalYearNotContiguous,
            fn () => createFiscalYearAs($actor, ['name' => 'FY-'.$why, 'start_date' => $start]),
            $why,
        );

        expect($refused->field)->toBe('start_date')->and($refused->context)->toBe(['expected_start' => '2027-01-01']);
    }

    expect(calendarCounts())->toBe($before);

    $next = createFiscalYearAs($actor, ['name' => 'FY2027', 'start_date' => '2027-01-01']);

    expect(FinanceSeed::connection()->table('fiscal_years')->where('id', $next)->value('end_date'))->toBe('2027-12-31');
});

it('lets the first year of a tenant start on any first of a month (AC-33, D-8)', function () {
    $actor = FinanceSeed::admin();

    $id = createFiscalYearAs($actor, ['name' => 'FY-odd', 'start_date' => '2030-04-01']);

    expect(FinanceSeed::connection()->table('fiscal_years')->where('id', $id)->value('end_date'))->toBe('2031-03-31');
});

it('refuses a name that differs only in case or edge whitespace (AC-34)', function () {
    $actor = FinanceSeed::admin();
    createFiscalYearAs($actor, ['name' => 'FY26', 'start_date' => '2026-01-01']);
    $before = calendarCounts();

    foreach (['fy26', 'FY26 ', ' Fy26'] as $name) {
        FinanceRefusals::expect(
            FinanceRefusal::NameInUse,
            fn () => createFiscalYearAs($actor, ['name' => $name, 'start_date' => '2027-01-01']),
            $name,
        );
    }

    expect(calendarCounts())->toBe($before);
});

it('validates the name: required, trimmed, a string, and short enough to name its periods (AC-34)', function () {
    $actor = FinanceSeed::admin();

    $refused = [null, '', '   ', 12, "FY\n26", str_repeat('A', 47), str_repeat('é', 47)];

    foreach ($refused as $name) {
        FinanceRefusals::expect(
            FinanceRefusal::Validation,
            fn () => createFiscalYearAs($actor, ['name' => $name, 'start_date' => '2026-01-01']),
            var_export($name, true),
        );
    }

    expect(calendarCounts())->toBe(['years' => 0, 'periods' => 0, 'audit' => 0]);

    $longest = str_repeat('é', 46);
    $id = createFiscalYearAs($actor, ['name' => $longest, 'start_date' => '2026-01-01']);

    expect(mb_strlen((string) FinanceSeed::connection()->table('fiscal_periods')->where('fiscal_year_id', $id)->where('sequence', 12)->value('name')))
        ->toBe(50);
});

it('refuses every malformed or out-of-range start date (AC-32)', function () {
    $actor = FinanceSeed::admin();

    $bad = ['2026-02-30', '2026-13-01', '26-1-1', '2026-1-1', '2026-01-01 00:00:00', '', null, '2026-01-02', '1999-01-01', '2101-01-01', 20260101, "2026-01-01\n"];

    foreach ($bad as $start) {
        FinanceRefusals::expect(
            FinanceRefusal::InvalidDate,
            fn () => createFiscalYearAs($actor, ['name' => 'FY', 'start_date' => $start]),
            var_export($start, true),
        );
    }

    expect(calendarCounts())->toBe(['years' => 0, 'periods' => 0, 'audit' => 0]);
});

it('refuses every key beyond name and start_date, naming them all (AC-36)', function () {
    $actor = FinanceSeed::admin();

    $refused = FinanceRefusals::expect(
        FinanceRefusal::Validation,
        fn () => createFiscalYearAs($actor, ['name' => 'FY', 'start_date' => '2026-01-01', 'status' => 'closed', 'end_date' => '2026-06-30']),
    );

    expect($refused->context['fields'])->toBe(['status', 'end_date']);
});

it('refuses a period start the engine already holds, and leaves nothing behind (AC-35 backstop)', function () {
    $actor = FinanceSeed::admin();
    $first = createFiscalYearAs($actor, ['name' => 'FY2026', 'start_date' => '2026-01-01']);

    // A stray period whose start collides with the third period of 2027, in a
    // year the contiguity pre-check cannot see: the unique index is what fires.
    FinanceSeed::connection()->table('fiscal_periods')->insert([
        'id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'fiscal_year_id' => $first, 'sequence' => 13, 'name' => 'stray',
        'start_date' => '2027-03-01', 'end_date' => '2027-03-31', 'status' => 'open',
    ]);
    $before = calendarCounts();

    $refused = FinanceRefusals::expect(
        FinanceRefusal::FiscalYearNotContiguous,
        fn () => createFiscalYearAs($actor, ['name' => 'FY2027', 'start_date' => '2027-01-01']),
    );

    expect($refused->field)->toBe('start_date')
        ->and(calendarCounts())->toBe($before);
});

it('verifies the whole chain after inserting, so a gapped year slipped in between is caught (AC-35, M15)', function () {
    $actor = FinanceSeed::admin();
    createFiscalYearAs($actor, ['name' => 'FY2026', 'start_date' => '2026-01-01']);
    $before = calendarCounts();

    // The other writer: after our year is in, a year that leaves a gap lands.
    app()->instance(PeriodRows::class, new class(app(TenantConnection::class)) extends PeriodRows
    {
        public function insertYear(array $row): void
        {
            parent::insertYear($row);

            parent::insertYear([
                'id' => '01JYYYYYYYYYYYYYYYYYYYYYYY', 'created_at' => '2026-11-10 10:00:00.000', 'updated_at' => '2026-11-10 10:00:00.000',
                'name' => 'Gapped', 'start_date' => '2029-01-01', 'end_date' => '2029-12-31', 'status' => 'open',
            ]);
        }
    });

    FinanceRefusals::expect(
        FinanceRefusal::FiscalYearNotContiguous,
        fn () => createFiscalYearAs($actor, ['name' => 'FY2027', 'start_date' => '2027-01-01']),
    );

    expect(calendarCounts())->toBe($before);
});

it('refuses an actor without the manage key before reading anything (AC-64)', function () {
    $viewer = FinanceSeed::actor('viewer', 'finance.periods.view');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => createFiscalYearAs($viewer, ['name' => 'FY', 'start_date' => '2026-01-01']));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => createFiscalYearAs(null, ['name' => 'FY', 'start_date' => '2026-01-01']));

    expect(calendarCounts())->toBe(['years' => 0, 'periods' => 0, 'audit' => 0]);
});

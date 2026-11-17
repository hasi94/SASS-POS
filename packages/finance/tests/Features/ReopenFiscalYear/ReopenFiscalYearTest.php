<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\ReopenFiscalYear;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\ReopenFiscalYear\ReopenFiscalYear;
use Flexipos\Finance\Features\ReopenFiscalYear\ReopenFiscalYearHandler;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriod;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriodHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 14 - reopening a fiscal year (AC-42, AC-46, AC-64)
|--------------------------------------------------------------------------
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

function reopenYearAs(?string $actorId, string $yearId, mixed $reason): void
{
    app(ReopenFiscalYearHandler::class)->handle(new ReopenFiscalYear($yearId, $reason, $actorId));
}

/**
 * @return array{id: string, periods: array<int, string>}
 */
function closedYear(string $start, ?string $name = null): array
{
    return FinanceSeed::fiscalYear($start, $name, ['status' => 'closed', 'closed_at' => FinanceSeed::NOW], array_fill(1, 12, 'closed'));
}

it('reopens the latest closed year with a reason, clearing who closed it, and records the reason (AC-46, AC-42)', function () {
    $reopener = FinanceSeed::actor('reopener', 'finance.periods.reopen');
    $year = closedYear('2026-01-01');
    $before = FinanceSeed::yearRow($year['id']);

    reopenYearAs($reopener, $year['id'], 'Auditor found a missing invoice');

    $after = FinanceSeed::yearRow($year['id']);

    expect($after)->toBe(array_merge($before, ['status' => 'open', 'closed_at' => null, 'closed_by' => null, 'updated_at' => '2026-11-10 10:00:00.000']))
        ->and(FinanceSeed::onlyAuditRow())->toMatchArray(['action' => 'fiscal_year_reopened', 'subject_id' => $year['id'], 'before' => $before])
        ->and(FinanceSeed::onlyAuditRow()['after'])->toBe([...$after, 'reason' => 'Auditor found a missing invoice'])
        // The periods stay closed: reopening a year does not reopen its months.
        ->and(FinanceSeed::connection()->table('fiscal_periods')->where('fiscal_year_id', $year['id'])->where('status', 'closed')->count())->toBe(12);
});

it('refuses a reopen without a reason of 1 to 500 characters (AC-42, AC-46)', function () {
    $reopener = FinanceSeed::actor('reopener', 'finance.periods.reopen');
    $year = closedYear('2026-01-01');
    $before = FinanceSeed::calendarSnapshot();

    foreach ([null, '', "  \t ", 12, str_repeat('x', 501)] as $reason) {
        FinanceRefusals::expect(FinanceRefusal::ReasonRequired, fn () => reopenYearAs($reopener, $year['id'], $reason), var_export($reason, true));
    }

    expect(FinanceSeed::calendarSnapshot())->toBe($before);

    reopenYearAs($reopener, $year['id'], str_repeat('x', 500));

    expect(FinanceSeed::yearRow($year['id'])['status'])->toBe('open');
});

it('refuses a reopen while any later year is not open, naming it (AC-46)', function () {
    $reopener = FinanceSeed::actor('reopener', 'finance.periods.reopen');
    $a = closedYear('2026-01-01');
    $b = closedYear('2027-01-01');
    $c = FinanceSeed::fiscalYear('2028-01-01');
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::LaterYearNotOpen, fn () => reopenYearAs($reopener, $a['id'], 'redo'));

    expect($refused->context)->toBe(['years' => [$b['id'] => 'closed']])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);

    reopenYearAs($reopener, $b['id'], 'redo');
    reopenYearAs($reopener, $a['id'], 'redo');

    expect(FinanceSeed::connection()->table('fiscal_years')->where('status', 'open')->count())->toBe(3)
        ->and($c['id'])->not->toBe('');
});

it('refuses reopening a year that is not closed (AC-46)', function () {
    $reopener = FinanceSeed::actor('reopener', 'finance.periods.reopen');
    $year = FinanceSeed::fiscalYear('2026-01-01');

    $refused = FinanceRefusals::expect(FinanceRefusal::InvalidTransition, fn () => reopenYearAs($reopener, $year['id'], 'why'));

    expect($refused->context)->toBe(['status' => 'open'])
        ->and(FinanceSeed::audit())->toBe([]);
});

it('needs the reopen key, which the close key does not imply (AC-46, AC-64)', function () {
    $closer = FinanceSeed::actor('closer', 'finance.periods.close', 'finance.periods.manage');
    $year = closedYear('2026-01-01');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => reopenYearAs($closer, $year['id'], 'redo'));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => reopenYearAs(null, $year['id'], 'redo'));

    expect(FinanceSeed::yearRow($year['id'])['status'])->toBe('closed');
});

it('refuses an unknown year and a malformed id (AC-46)', function () {
    $reopener = FinanceSeed::actor('reopener', 'finance.periods.reopen');

    FinanceRefusals::expect(FinanceRefusal::FiscalYearNotFound, fn () => reopenYearAs($reopener, (string) Str::ulid(), 'redo'));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => reopenYearAs($reopener, 'nope', 'redo'));

    expect(FinanceSeed::audit())->toBe([]);
});

it('moves a period of a reopened year again, which a closed year refused (AC-46)', function () {
    $reopener = FinanceSeed::actor('reopener', 'finance.periods.reopen');
    $year = closedYear('2026-01-01');

    reopenYearAs($reopener, $year['id'], 'redo');

    app(TransitionFiscalPeriodHandler::class)->handle(
        new TransitionFiscalPeriod(
            $year['periods'][12],
            ['status' => 'soft_closed', 'expected_status' => 'closed', 'reason' => 'fix'],
            $reopener,
        ),
    );

    expect(FinanceSeed::periodRow($year['periods'][12])['status'])->toBe('soft_closed');
});

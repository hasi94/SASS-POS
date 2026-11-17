<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Qa;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\PeriodBlocker;
use Flexipos\Contracts\Finance\PeriodGate;
use Flexipos\Contracts\Finance\PeriodGateRefused;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\CloseFiscalYear\CloseFiscalYear;
use Flexipos\Finance\Features\CloseFiscalYear\CloseFiscalYearHandler;
use Flexipos\Finance\Features\CreateAccount\CreateAccount;
use Flexipos\Finance\Features\CreateAccount\CreateAccountHandler;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYear;
use Flexipos\Finance\Features\CreateFiscalYear\CreateFiscalYearHandler;
use Flexipos\Finance\Features\DeleteFiscalYear\DeleteFiscalYear;
use Flexipos\Finance\Features\DeleteFiscalYear\DeleteFiscalYearHandler;
use Flexipos\Finance\Features\RenameFiscalPeriod\RenameFiscalPeriod;
use Flexipos\Finance\Features\RenameFiscalPeriod\RenameFiscalPeriodHandler;
use Flexipos\Finance\Features\RenameFiscalYear\RenameFiscalYear;
use Flexipos\Finance\Features\RenameFiscalYear\RenameFiscalYearHandler;
use Flexipos\Finance\Features\ReopenFiscalYear\ReopenFiscalYear;
use Flexipos\Finance\Features\ReopenFiscalYear\ReopenFiscalYearHandler;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriod;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriodHandler;
use Flexipos\Finance\Tests\Support\FakeUsagePorts;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 QA - end-to-end lifecycle and exploratory probes
|--------------------------------------------------------------------------
|
| Drives the real handlers through a whole year (create, soft-close and close
| every period, close the year, gate, reopen, next year) so that a defect that
| each slice test misses in isolation shows up in the chain. SQLite stand-in
| only: engine-only clauses stay NOT VERIFIED.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/finance-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();
    FakeUsagePorts::bind();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-10 10:00:00.000'));
});

afterEach(function () {
    FakeUsagePorts::reset();
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function qaMove(string $actor, string $periodId, string $to, string $from, ?string $reason = null): void
{
    $fields = ['status' => $to, 'expected_status' => $from];

    if ($reason !== null) {
        $fields['reason'] = $reason;
    }

    app(TransitionFiscalPeriodHandler::class)->handle(new TransitionFiscalPeriod($periodId, $fields, $actor));
}

function qaCreateYear(string $actor, string $name, string $start): string
{
    return app(CreateFiscalYearHandler::class)
        ->handle(new CreateFiscalYear(['name' => $name, 'start_date' => $start], $actor))->fiscalYearId;
}

/**
 * @return list<string> period ids by sequence order
 */
function qaPeriodIds(string $yearId): array
{
    return FinanceSeed::connection()->table('fiscal_periods')->where('fiscal_year_id', $yearId)
        ->orderBy('sequence')->pluck('id')->all();
}

function qaGate(mixed $date, ?string $actor): string
{
    return FinanceSeed::connection()->transaction(
        static fn (): string => app(PeriodGate::class)->clear($date, $actor)->periodId,
    );
}

it('runs a whole year: create, close in order, close the year, gate refuses, reopen, next year (AC-31 to AC-46, AC-47)', function () {
    $admin = FinanceSeed::admin();
    $year = qaCreateYear($admin, 'FY2026', '2026-01-01');
    $periods = qaPeriodIds($year);

    expect($periods)->toHaveCount(12)
        ->and(FinanceSeed::yearRow($year)['end_date'])->toBe('2026-12-31');

    // A year with an open period cannot be closed.
    FinanceRefusals::expect(FinanceRefusal::PeriodsNotClosed, fn () => app(CloseFiscalYearHandler::class)->handle(new CloseFiscalYear($year, $admin)));

    foreach ($periods as $id) {
        qaMove($admin, $id, 'soft_closed', 'open');
        qaMove($admin, $id, 'closed', 'soft_closed');
    }

    app(CloseFiscalYearHandler::class)->handle(new CloseFiscalYear($year, $admin));

    expect(FinanceSeed::yearRow($year))->toMatchArray(['status' => 'closed', 'closed_by' => $admin, 'retained_earnings_entry_id' => null]);

    // Closed beats every permission, first day and last day alike (AC-47, AC-49).
    foreach (['2026-01-01', '2026-06-15', '2026-12-31'] as $day) {
        expect(fn () => qaGate($day, $admin))->toThrow(PeriodGateRefused::class);
    }

    // The year is closed: reopening a period needs the year reopened first (AC-46).
    FinanceRefusals::expect(FinanceRefusal::FiscalYearClosed, fn () => qaMove($admin, $periods[11], 'soft_closed', 'closed', 'late invoice'));

    // A year may not be deleted when closed and not latest-with-open-periods (AC-37).
    FinanceRefusals::capture(fn () => app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($year, $admin)));

    // Next year is contiguous only (AC-33).
    FinanceRefusals::expect(FinanceRefusal::FiscalYearNotContiguous, fn () => qaCreateYear($admin, 'FY2028', '2028-01-01'));
    $next = qaCreateYear($admin, 'FY2027', '2027-01-01');

    // Reopen refused while a later year is not open (AC-46) - the next year is open, so allowed.
    app(ReopenFiscalYearHandler::class)->handle(new ReopenFiscalYear($year, 'owner asked', $admin));
    expect(FinanceSeed::yearRow($year))->toMatchArray(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

    // Reopen leaves periods closed (as-built: the year reopen does not lock or move periods).
    expect(FinanceSeed::periodRow($periods[0])['status'])->toBe('closed')
        ->and(fn () => qaGate('2026-03-10', $admin))->toThrow(PeriodGateRefused::class);

    // Now a period can be reopened: last first (AC-40), reason required (AC-42).
    FinanceRefusals::expect(FinanceRefusal::PeriodOrderViolation, fn () => qaMove($admin, $periods[0], 'soft_closed', 'closed', 'x'));
    qaMove($admin, $periods[11], 'soft_closed', 'closed', 'redo December');
    $nobody = FinanceSeed::nobody();
    expect(fn () => qaGate('2026-12-31', $nobody))->toThrow(PeriodGateRefused::class);
    expect(qaGate('2026-12-31', $admin))->toBe($periods[11]);

    // The next year's periods are open, so 2027 resolves for everyone.
    expect(qaGate('2027-01-01', $nobody))->toBe(qaPeriodIds($next)[0]);
});

it('closes the gate on a year that is closed and reopened by a later year that is not open (AC-46)', function () {
    $admin = FinanceSeed::admin();
    $y1 = FinanceSeed::fiscalYear('2026-01-01', 'A', [], array_fill(1, 12, 'closed'));
    $y2 = FinanceSeed::fiscalYear('2027-01-01', 'B', ['status' => 'closed'], array_fill(1, 12, 'closed'));
    FinanceSeed::connection()->table('fiscal_years')->where('id', $y1['id'])->update(['status' => 'closed']);

    FinanceRefusals::expect(FinanceRefusal::LaterYearNotOpen, fn () => app(ReopenFiscalYearHandler::class)->handle(new ReopenFiscalYear($y1['id'], 'why', $admin)));
    expect(FinanceSeed::yearRow($y1['id'])['status'])->toBe('closed');
});

it('refuses a reopen of a year without a reason, and reopening an open year (AC-46, AC-42)', function () {
    $admin = FinanceSeed::admin();
    $y = FinanceSeed::fiscalYear('2026-01-01', 'A', ['status' => 'closed'], array_fill(1, 12, 'closed'));
    $before = FinanceSeed::calendarSnapshot();

    foreach ([null, '', '   ', str_repeat('x', 501)] as $bad) {
        FinanceRefusals::capture(fn () => app(ReopenFiscalYearHandler::class)->handle(new ReopenFiscalYear($y['id'], $bad, $admin)));
    }

    expect(FinanceSeed::calendarSnapshot())->toBe($before);

    app(ReopenFiscalYearHandler::class)->handle(new ReopenFiscalYear($y['id'], 'ok', $admin));
    FinanceRefusals::capture(fn () => app(ReopenFiscalYearHandler::class)->handle(new ReopenFiscalYear($y['id'], 'again', $admin)));
});

it('a null or unprivileged actor on every transition/gate-adjacent write is refused not_permitted and writes nothing (AC-64)', function () {
    $nobody = FinanceSeed::nobody();
    $y = FinanceSeed::fiscalYear('2026-01-01');
    $before = FinanceSeed::calendarSnapshot();

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => qaMove($nobody, $y['periods'][1], 'soft_closed', 'open'));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(CreateFiscalYearHandler::class)->handle(new CreateFiscalYear(['name' => 'X', 'start_date' => '2027-01-01'], null)));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($y['id'], null)));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(CloseFiscalYearHandler::class)->handle(new CloseFiscalYear($y['id'], $nobody)));

    expect(FinanceSeed::calendarSnapshot())->toBe($before);
});

it('soft-closed periods allow only the post_adjustment holder, and a reopened period is open to everyone again (AC-47)', function () {
    $admin = FinanceSeed::admin();
    $adjuster = FinanceSeed::actor('adjuster', 'finance.periods.post_adjustment');
    $nobody = FinanceSeed::nobody();
    $y = FinanceSeed::fiscalYear('2026-01-01');

    qaMove($admin, $y['periods'][1], 'soft_closed', 'open');

    expect(qaGate('2026-01-31', $adjuster))->toBe($y['periods'][1])
        ->and(fn () => qaGate('2026-01-31', $nobody))->toThrow(PeriodGateRefused::class)
        ->and(fn () => qaGate('2026-01-31', null))->toThrow(PeriodGateRefused::class);

    qaMove($admin, $y['periods'][1], 'open', 'soft_closed', 'reopen');

    expect(qaGate('2026-01-31', $nobody))->toBe($y['periods'][1]);
});

it('keeps the gate strict for odd date inputs: non-strings, objects, padded, slashed, time-suffixed (AC-48)', function () {
    $admin = FinanceSeed::admin();
    FinanceSeed::fiscalYear('2026-01-01');

    foreach ([20260115, 2026.0115, true, [], new \DateTimeImmutable('2026-01-15'), ' 2026-01-15', '2026-01-15 ', "2026-01-15\n", '2026/01/15', '2026-01-15T00:00:00', '2026-1-15', '', null, '0000-00-00', '2026-02-29', '9999-12-31'] as $bad) {
        $caught = null;

        try {
            qaGate($bad, $admin);
        } catch (PeriodGateRefused $refused) {
            $caught = $refused;
        }

        expect($caught)->not->toBeNull(var_export($bad, true));
    }
});

it('refuses an account code carrying a trailing newline, and a null byte (AC-2, PHP $ trap)', function () {
    $admin = FinanceSeed::admin();

    foreach (["AB1\n", "AB1\0", "\nAB1", "A\tB", ' AB1', 'AB1 '] as $code) {
        FinanceRefusals::capture(fn () => app(CreateAccountHandler::class)->handle(new CreateAccount(['code' => $code, 'name' => 'N', 'type' => 'asset'], $admin)));
    }

    expect(FinanceSeed::connection()->table('accounts')->count())->toBe(0);
});

it('measures the year-name cap and period-name behaviour the spec disagrees with (AC-34 as-built)', function () {
    $admin = FinanceSeed::admin();
    $accepted = [];

    foreach ([1, 46, 47, 50, 51] as $len) {
        FinanceSeed::clearSeed();

        try {
            qaCreateYear($admin, str_repeat('N', $len), '2026-01-01');
            $accepted[$len] = true;
        } catch (\Throwable) {
            $accepted[$len] = false;
        }
    }

    // As-built: 46 is the cap (a 4-character " P12" suffix keeps period names within 50). The spec says 50.
    expect($accepted)->toBe([1 => true, 46 => true, 47 => false, 50 => false, 51 => false]);
});

it('deletes only the latest year, only with all periods open, and not when entries exist (AC-37)', function () {
    $admin = FinanceSeed::admin();
    $y1 = qaCreateYear($admin, 'FY2026', '2026-01-01');
    $y2 = qaCreateYear($admin, 'FY2027', '2027-01-01');

    foreach (qaPeriodIds($y1) as $id) {
        qaMove($admin, $id, 'soft_closed', 'open');
        qaMove($admin, $id, 'closed', 'soft_closed');
    }

    FinanceRefusals::expect(FinanceRefusal::NotLatestYear, fn () => app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($y1, $admin)));

    qaMove($admin, qaPeriodIds($y2)[0], 'soft_closed', 'open');
    FinanceRefusals::expect(FinanceRefusal::FiscalYearInUse, fn () => app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($y2, $admin)));
    qaMove($admin, qaPeriodIds($y2)[0], 'open', 'soft_closed', 'undo');

    FakeUsagePorts::$yearEntries[$y2] = true;
    FinanceRefusals::expect(FinanceRefusal::FiscalYearInUse, fn () => app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($y2, $admin)));
    FakeUsagePorts::$yearEntries = [];

    app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($y2, $admin));

    expect(FinanceSeed::connection()->table('fiscal_years')->count())->toBe(1)
        ->and(FinanceSeed::connection()->table('fiscal_periods')->count())->toBe(12);
});

it('pins as-built facts the spec disagrees with: rename is capped at 46 like create (D-31 a), period names are not unique, reopening a non-closed year is invalid_transition (AC-34, AC-46)', function () {
    $admin = FinanceSeed::admin();
    $a = qaCreateYear($admin, 'FY2026', '2026-01-01');
    $b = qaCreateYear($admin, 'FY2027', '2027-01-01');

    // Rename stops at 46 like create (D-31 a); 47 is refused, and so is the column width of 50.
    foreach ([47, 50] as $len) {
        FinanceRefusals::expect(FinanceRefusal::Validation, fn () => app(RenameFiscalYearHandler::class)
            ->handle(new RenameFiscalYear($a, ['name' => str_repeat('R', $len)], $admin)), (string) $len);
    }

    app(RenameFiscalYearHandler::class)
        ->handle(new RenameFiscalYear($a, ['name' => str_repeat('R', 46)], $admin));
    expect(FinanceSeed::yearRow($a)['name'])->toBe(str_repeat('R', 46));

    // Year names stay unique case-insensitively, trimmed.
    FinanceRefusals::expect(FinanceRefusal::NameInUse, fn () => app(RenameFiscalYearHandler::class)
        ->handle(new RenameFiscalYear($b, ['name' => '  '.strtolower(str_repeat('R', 46)).' '], $admin)));

    // Two periods may share one name.
    $ids = qaPeriodIds($a);
    foreach ([$ids[0], $ids[1]] as $id) {
        app(RenameFiscalPeriodHandler::class)
            ->handle(new RenameFiscalPeriod($id, ['name' => 'Same'], $admin));
    }
    expect(FinanceSeed::connection()->table('fiscal_periods')->where('name', 'Same')->count())->toBe(2);

    // Reopening an open year is invalid_transition.
    FinanceRefusals::expect(FinanceRefusal::InvalidTransition, fn () => app(ReopenFiscalYearHandler::class)->handle(new ReopenFiscalYear($a, 'why', $admin)));
});

it('refuses a soft-close from open when a blocker is reported, and lets the reopen through (AC-41)', function () {
    $admin = FinanceSeed::admin();
    $y = FinanceSeed::fiscalYear('2026-01-01');
    FakeUsagePorts::$blockers[$y['periods'][1]] = [new PeriodBlocker('draft_entry', 2)];
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::CloseBlocked, fn () => qaMove($admin, $y['periods'][1], 'soft_closed', 'open'));

    expect($refused->context)->toBe(['blockers' => ['draft_entry' => 2]])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);

    FakeUsagePorts::$blockers = [];
    qaMove($admin, $y['periods'][1], 'soft_closed', 'open');
    FakeUsagePorts::$blockers[$y['periods'][1]] = [new PeriodBlocker('draft_entry', 2)];
    qaMove($admin, $y['periods'][1], 'open', 'soft_closed', 'reopen despite blockers');

    expect(FinanceSeed::periodRow($y['periods'][1])['status'])->toBe('open');
});

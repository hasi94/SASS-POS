<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\CloseFiscalYear;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\CloseFiscalYear\CloseFiscalYear;
use Flexipos\Finance\Features\CloseFiscalYear\CloseFiscalYearHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 14 - closing a fiscal year (AC-45, AC-64, AC-65)
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

function closeYearAs(?string $actorId, string $yearId): void
{
    app(CloseFiscalYearHandler::class)->handle(new CloseFiscalYear($yearId, $actorId));
}

it('closes a year whose twelve periods are all closed, leaving the retained-earnings entry NULL (AC-45)', function () {
    $closer = FinanceSeed::actor('closer', 'finance.periods.close');
    $year = FinanceSeed::fiscalYear('2026-01-01', null, [], array_fill(1, 12, 'closed'));
    $before = FinanceSeed::yearRow($year['id']);

    closeYearAs($closer, $year['id']);

    $after = FinanceSeed::yearRow($year['id']);

    expect($after)->toBe(array_merge($before, [
        'status' => 'closed', 'closed_at' => '2026-11-10 10:00:00.000', 'closed_by' => $closer, 'updated_at' => '2026-11-10 10:00:00.000',
    ]))
        ->and($after['retained_earnings_entry_id'])->toBeNull()
        ->and(FinanceSeed::onlyAuditRow())->toMatchArray(['action' => 'fiscal_year_closed', 'subject_id' => $year['id'], 'before' => $before, 'after' => $after])
        ->and(FinanceSeed::connection()->table('fiscal_years')->where('status', 'closing')->count())->toBe(0);
});

it('refuses a year with any period not closed, naming each (AC-45)', function (string $status) {
    $closer = FinanceSeed::actor('closer', 'finance.periods.close');
    $year = FinanceSeed::fiscalYear('2026-01-01', null, [], array_fill(1, 11, 'closed') + [12 => $status]);
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::PeriodsNotClosed, fn () => closeYearAs($closer, $year['id']));

    expect($refused->context)->toBe(['periods' => [$year['periods'][12] => $status]])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);
})->with(['open', 'soft_closed']);

it('refuses a year that is already closed, writing no second audit row (AC-45)', function () {
    $closer = FinanceSeed::actor('closer', 'finance.periods.close');
    $year = FinanceSeed::fiscalYear('2026-01-01', null, [], array_fill(1, 12, 'closed'));

    closeYearAs($closer, $year['id']);

    $refused = FinanceRefusals::expect(FinanceRefusal::FiscalYearNotOpen, fn () => closeYearAs($closer, $year['id']));

    expect($refused->context)->toBe(['status' => 'closed'])
        ->and(FinanceSeed::connection()->table('audit_logs')->count())->toBe(1);
});

it('refuses an unknown year, a malformed id, and a caller without the close key (AC-45, AC-64)', function () {
    $closer = FinanceSeed::actor('closer', 'finance.periods.close');
    $reopener = FinanceSeed::actor('reopener', 'finance.periods.reopen', 'finance.periods.manage');
    $year = FinanceSeed::fiscalYear('2026-01-01', null, [], array_fill(1, 12, 'closed'));

    FinanceRefusals::expect(FinanceRefusal::FiscalYearNotFound, fn () => closeYearAs($closer, (string) Str::ulid()));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => closeYearAs($closer, 'nope'));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => closeYearAs($reopener, $year['id']));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => closeYearAs(null, $year['id']));

    expect(FinanceSeed::yearRow($year['id'])['status'])->toBe('open')
        ->and(FinanceSeed::audit())->toBe([]);
});

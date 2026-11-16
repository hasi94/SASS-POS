<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\DeleteFiscalYear;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\DeleteFiscalYear\DeleteFiscalYear;
use Flexipos\Finance\Features\DeleteFiscalYear\DeleteFiscalYearHandler;
use Flexipos\Finance\Tests\Support\FakeUsagePorts;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 13 - deleting a fiscal year (AC-37, AC-65)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/finance-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    financeEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function deleteYearAs(?string $actorId, string $yearId): void
{
    app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($yearId, $actorId));
}

it('deletes the latest year and its periods, with one audit row (AC-37)', function () {
    $actor = FinanceSeed::admin();
    FinanceSeed::fiscalYear('2026-01-01');
    $latest = FinanceSeed::fiscalYear('2027-01-01');
    $row = FinanceSeed::yearRow($latest['id']);

    deleteYearAs($actor, $latest['id']);

    expect(FinanceSeed::yearRow($latest['id']))->toBeNull()
        ->and(FinanceSeed::connection()->table('fiscal_periods')->where('fiscal_year_id', $latest['id'])->count())->toBe(0)
        ->and(FinanceSeed::connection()->table('fiscal_years')->count())->toBe(1)
        ->and(FinanceSeed::connection()->table('fiscal_periods')->count())->toBe(12)
        ->and(FinanceSeed::onlyAuditRow())->toMatchArray([
            'action' => 'fiscal_year_deleted', 'subject_id' => $latest['id'], 'before' => $row, 'after' => null,
        ]);
});

it('refuses a year that is not the latest (AC-37)', function () {
    $actor = FinanceSeed::admin();
    $first = FinanceSeed::fiscalYear('2026-01-01');
    $latest = FinanceSeed::fiscalYear('2027-01-01');
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::NotLatestYear, fn () => deleteYearAs($actor, $first['id']));

    expect($refused->context)->toBe(['latest' => $latest['id']])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);
});

it('refuses a year with a soft-closed or closed period, naming it (AC-37)', function (string $status) {
    $actor = FinanceSeed::admin();
    $year = FinanceSeed::fiscalYear('2026-01-01', null, [], [4 => $status]);
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::FiscalYearInUse, fn () => deleteYearAs($actor, $year['id']));

    expect($refused->context['periods'])->toBe([$year['periods'][4] => $status])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);
})->with(['soft_closed', 'closed']);

it('refuses a year the usage port says has journal entries (AC-37, AC-25)', function () {
    $actor = FinanceSeed::admin();
    $year = FinanceSeed::fiscalYear('2026-01-01');

    FakeUsagePorts::bind();

    // The control: with the double bound and reporting nothing, the delete goes through.
    deleteYearAs($actor, $year['id']);

    expect(FinanceSeed::yearRow($year['id']))->toBeNull();

    $year = FinanceSeed::fiscalYear('2026-01-01');
    FakeUsagePorts::$yearEntries[$year['id']] = true;
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::FiscalYearInUse, fn () => deleteYearAs($actor, $year['id']));

    expect($refused->context)->toBe(['entries' => true])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);
});

it('refuses an unknown year and a malformed id (AC-37)', function () {
    $actor = FinanceSeed::admin();

    FinanceRefusals::expect(FinanceRefusal::FiscalYearNotFound, fn () => deleteYearAs($actor, (string) Str::ulid()));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => deleteYearAs($actor, 'nope'));

    expect(FinanceSeed::audit())->toBe([]);
});

it('refuses an actor without the manage key (AC-64)', function () {
    $viewer = FinanceSeed::actor('viewer', 'finance.periods.view');
    $year = FinanceSeed::fiscalYear('2026-01-01');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => deleteYearAs($viewer, $year['id']));

    expect(FinanceSeed::yearRow($year['id']))->not->toBeNull();
});

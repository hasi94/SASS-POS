<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\ListFiscalPeriods;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\ListFiscalPeriods\ListFiscalPeriods;
use Flexipos\Finance\Features\ListFiscalPeriods\ListFiscalPeriodsHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 13 - the period list of one year (AC-62, AC-64)
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

it('lists a year\'s periods by sequence and none of another year\'s (AC-62)', function () {
    $actor = FinanceSeed::actor('viewer', 'finance.periods.view');
    $year = FinanceSeed::fiscalYear('2026-01-01');
    FinanceSeed::fiscalYear('2027-01-01');

    $rows = app(ListFiscalPeriodsHandler::class)->handle(new ListFiscalPeriods($year['id'], $actor));

    expect(array_column($rows, 'sequence'))->toBe(range(1, 12))
        ->and(array_column($rows, 'id'))->toBe(array_values($year['periods']));
});

it('refuses an unknown year and a malformed id (AC-62)', function () {
    $actor = FinanceSeed::actor('viewer', 'finance.periods.view');

    FinanceRefusals::expect(FinanceRefusal::FiscalYearNotFound, fn () => app(ListFiscalPeriodsHandler::class)->handle(new ListFiscalPeriods((string) Str::ulid(), $actor)));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => app(ListFiscalPeriodsHandler::class)->handle(new ListFiscalPeriods('nope', $actor)));
});

it('refuses a caller without the view key (AC-64)', function () {
    $manager = FinanceSeed::actor('manager', 'finance.periods.manage');
    $year = FinanceSeed::fiscalYear('2026-01-01');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(ListFiscalPeriodsHandler::class)->handle(new ListFiscalPeriods($year['id'], $manager)));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(ListFiscalPeriodsHandler::class)->handle(new ListFiscalPeriods($year['id'], null)));
});

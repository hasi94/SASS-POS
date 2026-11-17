<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\FindPeriodByDate;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\FindPeriodByDate\FindPeriodByDate;
use Flexipos\Finance\Features\FindPeriodByDate\FindPeriodByDateHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 13 - the lookup of a period by date (AC-62, AC-64)
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

it('finds the period covering a date at its edges, and refuses a date no period covers (AC-62)', function () {
    $actor = FinanceSeed::actor('viewer', 'finance.periods.view');
    $year = FinanceSeed::fiscalYear('2026-01-01');
    $handler = app(FindPeriodByDateHandler::class);

    expect($handler->handle(new FindPeriodByDate('2026-02-01', $actor))['id'])->toBe($year['periods'][2])
        ->and($handler->handle(new FindPeriodByDate('2026-02-28', $actor))['id'])->toBe($year['periods'][2])
        ->and($handler->handle(new FindPeriodByDate('2026-03-01', $actor))['id'])->toBe($year['periods'][3]);

    $refused = FinanceRefusals::expect(FinanceRefusal::NoFiscalPeriod, fn () => $handler->handle(new FindPeriodByDate('2027-01-01', $actor)));
    expect($refused->context)->toBe(['date' => '2027-01-01']);

    FinanceRefusals::expect(FinanceRefusal::NoFiscalPeriod, fn () => $handler->handle(new FindPeriodByDate('2025-12-31', $actor)));
});

it('refuses every malformed date (AC-62, AC-48)', function () {
    $actor = FinanceSeed::actor('viewer', 'finance.periods.view');
    FinanceSeed::fiscalYear('2026-01-01');

    foreach ([null, '', '2026-2-1', '2026-02-30', '2026-02-01 00:00:00', 20260201, "2026-02-01\n"] as $date) {
        FinanceRefusals::expect(FinanceRefusal::InvalidDate, fn () => app(FindPeriodByDateHandler::class)->handle(new FindPeriodByDate($date, $actor)), var_export($date, true));
    }
});

it('finds nothing in a tenant with no year (AC-62, AC-48)', function () {
    $actor = FinanceSeed::actor('viewer', 'finance.periods.view');

    FinanceRefusals::expect(FinanceRefusal::NoFiscalPeriod, fn () => app(FindPeriodByDateHandler::class)->handle(new FindPeriodByDate('2026-06-15', $actor)));
});

it('refuses a caller without the view key (AC-64)', function () {
    $manager = FinanceSeed::actor('manager', 'finance.periods.manage', 'finance.periods.close');
    FinanceSeed::fiscalYear('2026-01-01');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(FindPeriodByDateHandler::class)->handle(new FindPeriodByDate('2026-02-01', $manager)));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(FindPeriodByDateHandler::class)->handle(new FindPeriodByDate('2026-02-01', null)));
});

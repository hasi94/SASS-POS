<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\ListFiscalYears;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\ListFiscalYears\ListFiscalYears;
use Flexipos\Finance\Features\ListFiscalYears\ListFiscalYearsHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 13 - the fiscal year list (AC-62, AC-64)
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

it('lists years ascending by start date and pages them with a keyset cursor (AC-62)', function () {
    $actor = FinanceSeed::actor('viewer', 'finance.periods.view');
    // Inserted out of order on purpose: the order is by start_date, not insertion.
    $c = FinanceSeed::fiscalYear('2028-01-01');
    $a = FinanceSeed::fiscalYear('2026-01-01');
    $b = FinanceSeed::fiscalYear('2027-01-01');

    $handler = app(ListFiscalYearsHandler::class);

    $first = $handler->handle(new ListFiscalYears(2, null, $actor));

    expect(array_column($first->rows, 'id'))->toBe([$a['id'], $b['id']])
        ->and($first->nextAfterCode)->toBe('2027-01-01')
        ->and($first->nextAfterId)->toBeNull();

    $second = $handler->handle(new ListFiscalYears(2, $first->nextAfterCode, $actor));

    expect(array_column($second->rows, 'id'))->toBe([$c['id']])
        ->and($second->nextAfterCode)->toBeNull();

    expect(array_column($handler->handle(new ListFiscalYears(null, null, $actor))->rows, 'start_date'))
        ->toBe(['2026-01-01', '2027-01-01', '2028-01-01']);
});

it('refuses a page size outside 1 to 200 and a malformed cursor (AC-61, AC-62)', function () {
    $actor = FinanceSeed::actor('viewer', 'finance.periods.view');
    $handler = app(ListFiscalYearsHandler::class);

    foreach ([0, -1, 201, '10', 1.5, true] as $size) {
        FinanceRefusals::expect(FinanceRefusal::PageSizeInvalid, fn () => $handler->handle(new ListFiscalYears($size, null, $actor)), var_export($size, true));
    }

    foreach (['2026-1-1', 'x', 5, '2026-02-30'] as $cursor) {
        FinanceRefusals::expect(FinanceRefusal::CursorInvalid, fn () => $handler->handle(new ListFiscalYears(10, $cursor, $actor)), var_export($cursor, true));
    }

    expect($handler->handle(new ListFiscalYears(200, null, $actor))->rows)->toBe([]);
});

it('refuses a caller without the view key (AC-64)', function () {
    $manager = FinanceSeed::actor('manager', 'finance.periods.manage', 'finance.periods.close');
    FinanceSeed::fiscalYear('2026-01-01');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(ListFiscalYearsHandler::class)->handle(new ListFiscalYears(null, null, $manager)));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(ListFiscalYearsHandler::class)->handle(new ListFiscalYears(null, null, null)));
});

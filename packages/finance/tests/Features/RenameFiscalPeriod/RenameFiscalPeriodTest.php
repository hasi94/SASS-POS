<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\RenameFiscalPeriod;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\RenameFiscalPeriod\RenameFiscalPeriod;
use Flexipos\Finance\Features\RenameFiscalPeriod\RenameFiscalPeriodHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 13 - renaming a period (AC-34, AC-36, AC-65, D-28)
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

/**
 * @param  array<string, mixed>  $fields
 */
function renamePeriodAs(?string $actorId, string $periodId, array $fields): void
{
    app(RenameFiscalPeriodHandler::class)->handle(new RenameFiscalPeriod($periodId, $fields, $actorId));
}

it('renames a period, with the audit row as its only record (AC-34, AC-65, D-28)', function () {
    $actor = FinanceSeed::admin();
    $year = FinanceSeed::fiscalYear('2026-01-01');
    $before = FinanceSeed::periodRow($year['periods'][3]);

    renamePeriodAs($actor, $year['periods'][3], ['name' => ' March close ']);

    $after = FinanceSeed::periodRow($year['periods'][3]);

    expect($after)->toBe(array_merge($before, ['name' => 'March close']))
        ->and(FinanceSeed::onlyAuditRow())->toMatchArray([
            'action' => 'fiscal_period_renamed',
            'subject_id' => $year['periods'][3],
            'before' => $before,
            'after' => $after,
        ]);
});

it('allows two periods to share a name (AC-34: no uniqueness rule for periods)', function () {
    $actor = FinanceSeed::admin();
    $year = FinanceSeed::fiscalYear('2026-01-01');

    renamePeriodAs($actor, $year['periods'][1], ['name' => 'Same']);
    renamePeriodAs($actor, $year['periods'][2], ['name' => 'Same']);

    expect(FinanceSeed::connection()->table('fiscal_periods')->where('name', 'Same')->count())->toBe(2);
});

it('refuses each forbidden key in a rename body, whole-request (AC-36)', function (string $key, mixed $value) {
    $actor = FinanceSeed::admin();
    $year = FinanceSeed::fiscalYear('2026-01-01');
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::Validation, fn () => renamePeriodAs($actor, $year['periods'][1], ['name' => 'Renamed', $key => $value]));

    expect($refused->context['fields'])->toBe([$key])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);
})->with([
    ['start_date', '2026-02-01'], ['end_date', '2026-12-31'], ['status', 'closed'], ['sequence', 5],
    ['closed_at', '2026-11-10 10:00:00.000'], ['closed_by', null], ['fiscal_year_id', null],
]);

it('validates the name: required, a string, no control character, at most 50 characters (AC-34)', function () {
    $actor = FinanceSeed::admin();
    $year = FinanceSeed::fiscalYear('2026-01-01');
    $before = FinanceSeed::calendarSnapshot();

    foreach ([null, '', '  ', 5, "a\0b", str_repeat('x', 51)] as $name) {
        FinanceRefusals::expect(FinanceRefusal::Validation, fn () => renamePeriodAs($actor, $year['periods'][1], ['name' => $name]), var_export($name, true));
    }

    expect(FinanceSeed::calendarSnapshot())->toBe($before);

    renamePeriodAs($actor, $year['periods'][1], ['name' => str_repeat('é', 50)]);

    expect(mb_strlen((string) FinanceSeed::periodRow($year['periods'][1])['name']))->toBe(50);
});

it('refuses an unknown period and a malformed id (AC-36)', function () {
    $actor = FinanceSeed::admin();

    FinanceRefusals::expect(FinanceRefusal::PeriodNotFound, fn () => renamePeriodAs($actor, (string) Str::ulid(), ['name' => 'X']));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => renamePeriodAs($actor, 'nope', ['name' => 'X']));

    expect(FinanceSeed::audit())->toBe([]);
});

it('refuses an actor without the manage key (AC-64)', function () {
    $closer = FinanceSeed::actor('closer', 'finance.periods.close');
    $year = FinanceSeed::fiscalYear('2026-01-01');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => renamePeriodAs($closer, $year['periods'][1], ['name' => 'X']));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => renamePeriodAs(null, $year['periods'][1], ['name' => 'X']));

    expect(FinanceSeed::audit())->toBe([]);
});

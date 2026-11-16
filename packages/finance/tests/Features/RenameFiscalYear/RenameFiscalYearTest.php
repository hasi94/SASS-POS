<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\RenameFiscalYear;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\RenameFiscalYear\RenameFiscalYear;
use Flexipos\Finance\Features\RenameFiscalYear\RenameFiscalYearHandler;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-032 plan step 13 - renaming a year (AC-34, AC-36, AC-65)
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

/**
 * @param  array<string, mixed>  $fields
 */
function renameYearAs(?string $actorId, string $yearId, array $fields): void
{
    app(RenameFiscalYearHandler::class)->handle(new RenameFiscalYear($yearId, $fields, $actorId));
}

it('renames a year, trimming the name, with one audit row naming both values (AC-34, AC-65)', function () {
    $actor = FinanceSeed::admin();
    $year = FinanceSeed::fiscalYear('2026-01-01');
    $before = FinanceSeed::yearRow($year['id']);

    renameYearAs($actor, $year['id'], ['name' => '  Financial 2026 ']);

    $after = FinanceSeed::yearRow($year['id']);

    expect($after)->toBe(array_merge($before, ['name' => 'Financial 2026', 'updated_at' => '2026-11-10 10:00:00.000']))
        ->and(FinanceSeed::onlyAuditRow())->toMatchArray([
            'action' => 'fiscal_year_renamed',
            'subject_id' => $year['id'],
            'before' => $before,
            'after' => $after,
        ]);
});

it('refuses a year name another year already has, ignoring case and edge whitespace, but not its own (AC-34)', function () {
    $actor = FinanceSeed::admin();
    $a = FinanceSeed::fiscalYear('2026-01-01', 'FY26');
    $b = FinanceSeed::fiscalYear('2027-01-01', 'FY27');
    $before = FinanceSeed::calendarSnapshot();

    foreach (['fy26', 'FY26 ', 'Fy26'] as $name) {
        FinanceRefusals::expect(FinanceRefusal::NameInUse, fn () => renameYearAs($actor, $b['id'], ['name' => $name]), $name);
    }

    expect(FinanceSeed::calendarSnapshot())->toBe($before);

    renameYearAs($actor, $a['id'], ['name' => 'fy26']);

    expect(FinanceSeed::yearRow($a['id'])['name'])->toBe('fy26');
});

it('refuses each forbidden key in a rename body, whole-request (AC-36)', function (string $key, mixed $value) {
    $actor = FinanceSeed::admin();
    $year = FinanceSeed::fiscalYear('2026-01-01');
    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::Validation, fn () => renameYearAs($actor, $year['id'], ['name' => 'Renamed', $key => $value]));

    expect($refused->context['fields'])->toBe([$key])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);
})->with([
    ['start_date', '2026-02-01'], ['end_date', '2026-12-31'], ['status', 'closed'], ['closed_at', '2026-11-10 10:00:00.000'],
    ['closed_by', null], ['retained_earnings_entry_id', null], ['id', '01ABC'], ['sequence', 1],
]);

it('validates the name: required, a string, no control character, at most 50 characters (AC-34)', function () {
    $actor = FinanceSeed::admin();
    $year = FinanceSeed::fiscalYear('2026-01-01');
    $before = FinanceSeed::calendarSnapshot();

    foreach ([null, '', '  ', 5, "a\0b", str_repeat('x', 51)] as $name) {
        FinanceRefusals::expect(FinanceRefusal::Validation, fn () => renameYearAs($actor, $year['id'], ['name' => $name]), var_export($name, true));
    }

    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => renameYearAs($actor, $year['id'], []));

    expect(FinanceSeed::calendarSnapshot())->toBe($before);

    renameYearAs($actor, $year['id'], ['name' => str_repeat('é', 50)]);

    expect(mb_strlen((string) FinanceSeed::yearRow($year['id'])['name']))->toBe(50);
});

it('refuses an unknown year and a malformed id (AC-36)', function () {
    $actor = FinanceSeed::admin();

    FinanceRefusals::expect(FinanceRefusal::FiscalYearNotFound, fn () => renameYearAs($actor, (string) Str::ulid(), ['name' => 'X']));
    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => renameYearAs($actor, 'nope', ['name' => 'X']));

    expect(FinanceSeed::audit())->toBe([]);
});

it('refuses an actor without the manage key (AC-64)', function () {
    $closer = FinanceSeed::actor('closer', 'finance.periods.close', 'finance.periods.view');
    $year = FinanceSeed::fiscalYear('2026-01-01');

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => renameYearAs($closer, $year['id'], ['name' => 'X']));
    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => renameYearAs(null, $year['id'], ['name' => 'X']));

    expect(FinanceSeed::audit())->toBe([]);
});

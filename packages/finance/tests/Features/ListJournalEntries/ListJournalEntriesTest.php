<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\ListJournalEntries;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Features\ListJournalEntries\ListJournalEntries;
use Flexipos\Finance\Features\ListJournalEntries\ListJournalEntriesHandler;
use Flexipos\Finance\Features\ListJournalEntries\ListJournalEntriesResult;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step D2 - ListJournalEntries (AC-69, D-36)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/journal-fixture.php';

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
 * @param  array<array-key, mixed>  $filters
 */
function listEntriesAs(?string $actor, array $filters = [], mixed $size = null, mixed $date = null, mixed $number = null, mixed $id = null): ListJournalEntriesResult
{
    return app(ListJournalEntriesHandler::class)->handle(new ListJournalEntries($filters, $size, $date, $number, $id, $actor));
}

function listViewer(): string
{
    return FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);
}

/**
 * @return list<string> the numbers of a page, in page order
 */
function listNumbers(ListJournalEntriesResult $page): array
{
    return array_column($page->rows, 'number');
}

/**
 * Five entries: JE-000001 to JE-000005 on five different dates, in date order.
 *
 * @param  array<string, mixed>  $world
 * @return list<string> the ids, oldest first
 */
function listFiveEntries(array $world): array
{
    $ids = [];

    foreach (['2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10'] as $date) {
        $ids[] = jrnPost(jrnBody($world, ['entry_date' => $date]), $world['actor'])->id;
    }

    return $ids;
}

it('returns summary rows, newest date first, with no lines key (AC-69)', function () {
    $world = jrnWorld();
    $ids = listFiveEntries($world);

    $page = listEntriesAs(listViewer());

    expect(listNumbers($page))->toBe(['JE-000005', 'JE-000004', 'JE-000003', 'JE-000002', 'JE-000001'])
        ->and(array_column($page->rows, 'id'))->toBe(array_reverse($ids))
        ->and($page->nextAfterEntryDate)->toBeNull()
        ->and($page->nextAfterNumber)->toBeNull()
        ->and($page->nextAfterId)->toBeNull();

    foreach ($page->rows as $row) {
        expect($row)->not->toHaveKey('lines')
            ->and($row)->not->toHaveKey('payload_fingerprint')
            ->and($row)->not->toHaveKey('source_purpose_key')
            ->and($row['total_debit'])->toBe('100.0000')
            ->and($row['is_auto'])->toBeFalse();
    }
});

it('pages by keyset over five entries at size two, and the last page carries no cursor (AC-69)', function () {
    $world = jrnWorld();
    listFiveEntries($world);
    $viewer = listViewer();

    $first = listEntriesAs($viewer, [], 2);

    expect(listNumbers($first))->toBe(['JE-000005', 'JE-000004'])
        ->and($first->nextAfterEntryDate)->toBe('2026-04-10')
        ->and($first->nextAfterNumber)->toBe('JE-000004')
        ->and($first->nextAfterId)->toBe($first->rows[1]['id']);

    $second = listEntriesAs($viewer, [], 2, $first->nextAfterEntryDate, $first->nextAfterNumber, $first->nextAfterId);

    expect(listNumbers($second))->toBe(['JE-000003', 'JE-000002'])
        ->and($second->nextAfterNumber)->toBe('JE-000002');

    $third = listEntriesAs($viewer, [], 2, $second->nextAfterEntryDate, $second->nextAfterNumber, $second->nextAfterId);

    expect(listNumbers($third))->toBe(['JE-000001'])
        ->and($third->nextAfterEntryDate)->toBeNull()
        ->and($third->nextAfterNumber)->toBeNull()
        ->and($third->nextAfterId)->toBeNull();
});

it('breaks a date tie by number, with JE-000010 after JE-000009 on one date (AC-69, D-36)', function () {
    $world = jrnWorld();

    FinanceSeed::connection()->table('number_sequences')->where('document_type', 'journal_entry')->update(['next_number' => 9]);

    $nine = jrnPost(jrnBody($world, ['entry_date' => '2026-03-10']))->number;
    $ten = jrnPost(jrnBody($world, ['entry_date' => '2026-03-10']))->number;

    expect([$nine, $ten])->toBe(['JE-000009', 'JE-000010']);

    $viewer = listViewer();
    $page = listEntriesAs($viewer, [], 1);

    expect(listNumbers($page))->toBe(['JE-000010']);

    $next = listEntriesAs($viewer, [], 1, $page->nextAfterEntryDate, $page->nextAfterNumber, $page->nextAfterId);

    expect(listNumbers($next))->toBe(['JE-000009']);

    // The order is string order (D-36): a longer number with the same prefix sorts above the shorter one. The id is the
    // last tiebreak, and it cannot be reached here because `number` is unique.
    jrnRawEntry($world['periods'][3], ['entry_date' => '2026-03-10', 'number' => 'JE-000010-X']);

    expect(listNumbers(listEntriesAs($viewer)))->toBe(['JE-000010-X', 'JE-000010', 'JE-000009']);
});

it('uses the configured default page size, 50 as shipped, and refuses a size outside 1 to 200 (AC-69)', function () {
    $world = jrnWorld();
    listFiveEntries($world);
    $viewer = listViewer();

    expect(config('finance.page_size'))->toBe(50)
        ->and(config('finance.max_page_size'))->toBe(200);

    config()->set('finance.page_size', 2);

    expect(listEntriesAs($viewer)->rows)->toHaveCount(2)
        ->and(listEntriesAs($viewer, [], 200)->rows)->toHaveCount(5)
        ->and(listEntriesAs($viewer, [], 1)->rows)->toHaveCount(1);

    foreach ([0, 201, -1, '50', 50.0, 2.5, true, []] as $bad) {
        FinanceRefusals::expect(FinanceRefusal::PageSizeInvalid, fn () => listEntriesAs($viewer, [], $bad), var_export($bad, true));
    }
});

it('refuses a partial or malformed cursor as cursor_invalid before reading (AC-69)', function (array $cursor) {
    $world = jrnWorld();
    listFiveEntries($world);
    $viewer = listViewer();

    [$statements, $thrown] = jrnStatements(fn () => listEntriesAs($viewer, [], 2, ...$cursor));

    if (! $thrown instanceof FinanceRefused) {
        Assert::fail('A FinanceRefused was expected.');
    }

    expect($thrown->reason)->toBe(FinanceRefusal::CursorInvalid)
        ->and(jrnStatementsOn($statements, 'journal_entries'))->toBe([]);
})->with(function () {
    $id = (string) Str::ulid();

    return [
        'date only' => [['date' => '2026-03-10']],
        'number only' => [['number' => 'JE-000001']],
        'id only' => [['id' => $id]],
        'date and number' => [['date' => '2026-03-10', 'number' => 'JE-000001']],
        'date and id' => [['date' => '2026-03-10', 'id' => $id]],
        'number and id' => [['number' => 'JE-000001', 'id' => $id]],
        'an impossible date' => [['date' => '2026-02-30', 'number' => 'JE-000001', 'id' => $id]],
        'a date-time' => [['date' => '2026-03-10 00:00:00', 'number' => 'JE-000001', 'id' => $id]],
        'a number with a space' => [['date' => '2026-03-10', 'number' => 'JE 000001', 'id' => $id]],
        'an over-long number' => [['date' => '2026-03-10', 'number' => str_repeat('J', 31), 'id' => $id]],
        'a number as an integer' => [['date' => '2026-03-10', 'number' => 1, 'id' => $id]],
        'a malformed id' => [['date' => '2026-03-10', 'number' => 'JE-000001', 'id' => 'x']],
    ];
});

it('filters by date range, inclusive at both ends, and an inverted range is an empty page (AC-69)', function () {
    $world = jrnWorld();
    listFiveEntries($world);
    $viewer = listViewer();

    expect(listNumbers(listEntriesAs($viewer, ['from' => '2026-02-10', 'to' => '2026-04-10'])))->toBe(['JE-000004', 'JE-000003', 'JE-000002'])
        ->and(listNumbers(listEntriesAs($viewer, ['from' => '2026-02-11'])))->toBe(['JE-000005', 'JE-000004', 'JE-000003'])
        ->and(listNumbers(listEntriesAs($viewer, ['to' => '2026-02-09'])))->toBe(['JE-000001'])
        ->and(listEntriesAs($viewer, ['from' => '2026-04-01', 'to' => '2026-02-01'])->rows)->toBe([]);
});

it('filters by status, source type, period, account and branch (AC-69)', function () {
    $world = jrnWorld();
    $viewer = listViewer();
    $branch = AccessSeed::branch();

    $sale = jrnPost(jrnBody($world, ['entry_date' => '2026-01-10', 'branch_id' => $branch]), $world['actor']);
    $manual = jrnPost(jrnBody($world, [
        'source_type' => 'manual',
        'source_id' => null,
        'entry_date' => '2026-02-10',
        'lines' => [
            ['account_id' => $world['cash'], 'debit' => '5'],
            ['account_id' => $world['other'], 'credit' => '5'],
        ],
    ]), $world['actor']);

    jrnReverse($sale->id, '2026-03-10', 'undo', $world['actor']);

    $ids = static fn (array $filters): array => array_column(listEntriesAs($viewer, $filters)->rows, 'id');

    expect(count($ids([])))->toBe(3)
        ->and($ids(['status' => 'reversed']))->toBe([$sale->id])
        ->and(count($ids(['status' => 'posted'])))->toBe(2)
        ->and($ids(['status' => 'draft']))->toBe([])
        ->and($ids(['source_type' => 'manual']))->toBe([$manual->id])
        ->and(count($ids(['source_type' => 'reversal'])))->toBe(1)
        ->and($ids(['period_id' => $world['periods'][2]]))->toBe([$manual->id])
        ->and($ids(['branch_id' => $branch]))->toHaveCount(2, 'the sale and its reversal, which copies the branch')
        ->and($ids(['branch_id' => $branch, 'source_type' => 'sale']))->toBe([$sale->id])
        ->and($ids(['account_id' => $world['other']]))->toBe([$manual->id])
        ->and(count($ids(['account_id' => $world['cash']])))->toBe(3)
        ->and($ids(['account_id' => $world['sales'], 'source_type' => 'sale']))->toBe([$sale->id])
        ->and($ids(['account_id' => (string) Str::ulid()]))->toBe([]);
});

it('refuses an invalid filter, a case variant of an enum filter and an unknown filter key (AC-69)', function (array $filters) {
    $world = jrnWorld();
    listFiveEntries($world);

    [$statements, $thrown] = jrnStatements(fn () => listEntriesAs(listViewer(), $filters));

    if (! $thrown instanceof FinanceRefused) {
        Assert::fail('A FinanceRefused was expected.');
    }

    expect($thrown->reason)->toBe(FinanceRefusal::Validation)
        ->and(jrnStatementsOn($statements, 'journal_entries'))->toBe([]);
})->with([
    'status of the wrong case' => [['status' => 'Posted']],
    'status unknown' => [['status' => 'void']],
    'status as a list' => [['status' => ['posted']]],
    'source type of the wrong case' => [['source_type' => 'SALE']],
    'source type unknown' => [['source_type' => 'invoice']],
    'from impossible' => [['from' => '2026-02-30']],
    'from with a time' => [['from' => '2026-01-01 00:00:00']],
    'to short' => [['to' => '2026-1-1']],
    'to as a number' => [['to' => 20260101]],
    'period id malformed' => [['period_id' => 'x']],
    'account id malformed' => [['account_id' => 'x']],
    'branch id null' => [['branch_id' => null]],
    'an unknown filter key' => [['customer_id' => 'x']],
    'an unknown key beside a valid one' => [['status' => 'posted', 'q' => 'x']],
]);

it('writes nothing and records no audit row (AC-66)', function () {
    $world = jrnWorld();
    listFiveEntries($world);
    $before = jrnState();

    listEntriesAs(listViewer(), ['status' => 'posted'], 2);

    expect(jrnState())->toBe($before);
});

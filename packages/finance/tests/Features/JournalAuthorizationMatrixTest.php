<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Features\GetJournalEntry\GetJournalEntry;
use Flexipos\Finance\Features\GetJournalEntry\GetJournalEntryHandler;
use Flexipos\Finance\Features\ListAccounts\ListAccounts;
use Flexipos\Finance\Features\ListAccounts\ListAccountsHandler;
use Flexipos\Finance\Features\ListJournalEntries\ListJournalEntries;
use Flexipos\Finance\Features\ListJournalEntries\ListJournalEntriesHandler;
use Flexipos\Finance\Features\PostManualJournalEntry\PostManualJournalEntry;
use Flexipos\Finance\Features\PostManualJournalEntry\PostManualJournalEntryHandler;
use Flexipos\Finance\Features\ReverseJournalEntry\ReverseJournalEntry;
use Flexipos\Finance\Features\ReverseJournalEntry\ReverseJournalEntryHandler;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step D2 - the authorization matrix over the four journal slices
| (AC-68 to AC-71, AC-73, AC-72)
|--------------------------------------------------------------------------
|
| For each slice and each kind of caller without the key:
|
|   - the call is refused `not_permitted`, naming the key and nothing finer;
|   - **no statement on a journal, account or period table ran**, and **no
|     transaction began** (M14: an `Authorize` placed after the transaction
|     opens is red on the second half), both proven by listeners on the tenant
|     connection;
|   - the key's own holder is NOT refused `not_permitted` (the control: the
|     other cases would pass against a handler that refuses everyone).
|
| The matrix catches `FinanceRefused` and `PostingRefused` alike: a holder is let
| through to the service, whose own refusals are the contract's.
*/

uses(TestCase::class);

require_once __DIR__.'/../Support/journal-fixture.php';

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
 * Every journal slice: the key it needs, and a call that names a real target.
 *
 * @return array<string, array{0: string, 1: callable(?string, string): mixed}>
 */
function journalSlices(): array
{
    return [
        'GetJournalEntry (by id)' => [FinancePermissions::JOURNAL_VIEW, fn (?string $a, string $entry) => app(GetJournalEntryHandler::class)->handle(new GetJournalEntry($entry, null, null, null, $a))],
        'GetJournalEntry (by source key)' => [FinancePermissions::JOURNAL_VIEW, fn (?string $a) => app(GetJournalEntryHandler::class)->handle(new GetJournalEntry(null, 'sale', (string) Str::ulid(), null, $a))],
        'ListJournalEntries' => [FinancePermissions::JOURNAL_VIEW, fn (?string $a) => app(ListJournalEntriesHandler::class)->handle(new ListJournalEntries([], null, null, null, null, $a))],
        'PostManualJournalEntry' => [FinancePermissions::JOURNAL_POST_MANUAL, fn (?string $a) => app(PostManualJournalEntryHandler::class)->handle(new PostManualJournalEntry([], $a))],
        'ReverseJournalEntry' => [FinancePermissions::JOURNAL_REVERSE, fn (?string $a, string $entry) => app(ReverseJournalEntryHandler::class)->handle(new ReverseJournalEntry($entry, '2026-03-20', 'mistake', $a))],
    ];
}

/**
 * Run a slice, recording every statement and every transaction it began.
 *
 * @param  callable(?string, string): mixed  $slice
 * @return array{0: FinanceRefused|PostingRefused|null, 1: list<string>, 2: int}
 */
function journalRunRecorded(callable $slice, ?string $actor, string $entry): array
{
    $sql = [];
    $began = 0;

    $connection = FinanceSeed::connection();
    $connection->listen(static function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });
    $connection->getEventDispatcher()->listen(TransactionBeginning::class, static function () use (&$began): void {
        $began++;
    });

    $thrown = null;

    try {
        $slice($actor, $entry);
    } catch (FinanceRefused|PostingRefused $caught) {
        $thrown = $caught;
    }

    return [$thrown, $sql, $began];
}

function journalTouchesLedgerTables(string $sql): bool
{
    return preg_match('/\b(journal_entries|journal_lines|accounts|fiscal_years|fiscal_periods)\b/i', $sql) === 1;
}

it('refuses a caller without the key before any ledger statement runs or a transaction begins, for every journal slice (AC-68 to AC-71, AC-73)', function (string $name, string $callerKind) {
    [$key, $slice] = journalSlices()[$name];

    $world = jrnWorld();
    $entry = jrnPost(jrnBody($world), $world['actor'])->id;
    $before = jrnState();

    $actor = match ($callerKind) {
        'no key' => FinanceSeed::nobody(),
        'every other key' => FinanceSeed::actor('others', ...array_values(array_diff(FinancePermissions::KEYS, [$key]))),
        'null actor' => null,
        'deleted holder' => (function () use ($key): string {
            $id = FinanceSeed::actor('ghost', $key);
            FinanceSeed::connection()->table('employees')->where('id', $id)->update(['deleted_at' => FinanceSeed::NOW]);

            return $id;
        })(),
        default => throw new \InvalidArgumentException($callerKind),
    };

    [$thrown, $sql, $began] = journalRunRecorded($slice, $actor, $entry);

    if (! $thrown instanceof FinanceRefused) {
        Assert::fail('A FinanceRefused was expected.');
    }

    expect($thrown->reason)->toBe(FinanceRefusal::NotPermitted)
        ->and($thrown->context)->toBe(['permission' => $key])
        ->and(array_values(array_filter($sql, journalTouchesLedgerTables(...))))->toBe([])
        ->and($began)->toBe(0)
        ->and(jrnState())->toBe($before);
})->with(function () {
    foreach (array_keys(journalSlices()) as $name) {
        foreach (['no key', 'every other key', 'null actor', 'deleted holder'] as $kind) {
            yield "{$name} / {$kind}" => [$name, $kind];
        }
    }
});

it('lets the holder of exactly the key past authorization, for every journal slice (the control)', function (string $name) {
    [$key, $slice] = journalSlices()[$name];

    $world = jrnWorld();
    $entry = jrnPost(jrnBody($world), $world['actor'])->id;
    $actor = FinanceSeed::actor('holder', $key);

    [$thrown, $sql, $began] = journalRunRecorded($slice, $actor, $entry);

    // Whatever else happens (success, or a rule refusing the call), it is not an authorization refusal, and the
    // handler reached the ledger: a read slice a journal table, a write slice a transaction.
    $reason = $thrown instanceof FinanceRefused ? $thrown->reason : null;

    expect($reason)->not->toBe(FinanceRefusal::NotPermitted)
        ->and(array_values(array_filter($sql, journalTouchesLedgerTables(...))) !== [] || $began > 0)->toBeTrue();
})->with(array_keys(journalSlices()));

it('keeps the three keys apart: view grants neither write, and neither write grants view (AC-68 to AC-71)', function () {
    $world = jrnWorld();
    $entry = jrnPost(jrnBody($world), $world['actor'])->id;

    $viewer = FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);
    $poster = FinanceSeed::actor('poster', FinancePermissions::JOURNAL_POST_MANUAL);
    $reverser = FinanceSeed::actor('reverser', FinancePermissions::JOURNAL_REVERSE);

    $refused = static fn (callable $call): FinanceRefusal => FinanceRefusals::capture($call)->reason;

    expect($refused(fn () => app(ReverseJournalEntryHandler::class)->handle(new ReverseJournalEntry($entry, '2026-03-20', 'x', $viewer))))->toBe(FinanceRefusal::NotPermitted)
        ->and($refused(fn () => app(PostManualJournalEntryHandler::class)->handle(new PostManualJournalEntry([], $viewer))))->toBe(FinanceRefusal::NotPermitted)
        ->and($refused(fn () => app(GetJournalEntryHandler::class)->handle(new GetJournalEntry($entry, null, null, null, $poster))))->toBe(FinanceRefusal::NotPermitted)
        ->and($refused(fn () => app(GetJournalEntryHandler::class)->handle(new GetJournalEntry($entry, null, null, null, $reverser))))->toBe(FinanceRefusal::NotPermitted)
        ->and($refused(fn () => app(ListJournalEntriesHandler::class)->handle(new ListJournalEntries([], null, null, null, null, $reverser))))->toBe(FinanceRefusal::NotPermitted)
        ->and($refused(fn () => app(ReverseJournalEntryHandler::class)->handle(new ReverseJournalEntry($entry, '2026-03-20', 'x', $poster))))->toBe(FinanceRefusal::NotPermitted)
        ->and($refused(fn () => app(PostManualJournalEntryHandler::class)->handle(new PostManualJournalEntry([], $reverser))))->toBe(FinanceRefusal::NotPermitted);
});

it('does not grant a journal key to the account or period keys, nor the reverse (AC-72)', function () {
    $world = jrnWorld();
    $entry = jrnPost(jrnBody($world), $world['actor'])->id;

    $accountsAndPeriods = FinanceSeed::actor('chart', FinancePermissions::ACCOUNTS_VIEW, FinancePermissions::ACCOUNTS_MANAGE, FinancePermissions::PERIODS_VIEW, FinancePermissions::PERIODS_MANAGE, FinancePermissions::PERIODS_CLOSE, FinancePermissions::PERIODS_REOPEN, FinancePermissions::PERIODS_POST_ADJUSTMENT);
    $journalOnly = FinanceSeed::actor('ledger', FinancePermissions::JOURNAL_VIEW, FinancePermissions::JOURNAL_POST_MANUAL, FinancePermissions::JOURNAL_REVERSE);

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(GetJournalEntryHandler::class)->handle(new GetJournalEntry($entry, null, null, null, $accountsAndPeriods)));

    $listing = app(ListJournalEntriesHandler::class)->handle(new ListJournalEntries([], null, null, null, null, $journalOnly));

    expect($listing->rows)->toHaveCount(1);

    FinanceRefusals::expect(FinanceRefusal::NotPermitted, fn () => app(ListAccountsHandler::class)->handle(new ListAccounts([], null, null, null, $journalOnly)));
});

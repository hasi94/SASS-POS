<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Journal;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountUsage;
use Flexipos\Contracts\Finance\FiscalYearUsage;
use Flexipos\Contracts\Finance\PeriodBlocker;
use Flexipos\Contracts\Finance\PeriodCloseBlockers;
use Flexipos\Finance\Accounts\NoAccountUsage;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccount;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccountHandler;
use Flexipos\Finance\Features\DeleteFiscalYear\DeleteFiscalYear;
use Flexipos\Finance\Features\DeleteFiscalYear\DeleteFiscalYearHandler;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriod;
use Flexipos\Finance\Features\TransitionFiscalPeriod\TransitionFiscalPeriodHandler;
use Flexipos\Finance\Features\UpdateAccount\UpdateAccount;
use Flexipos\Finance\Features\UpdateAccount\UpdateAccountHandler;
use Flexipos\Finance\Journal\Ports\JournalAccountUsage;
use Flexipos\Finance\Journal\Ports\JournalFiscalYearUsage;
use Flexipos\Finance\Journal\Ports\JournalPeriodCloseBlockers;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step D1 - the three usage ports, rebound (AC-59 to AC-64, AC-79)
|--------------------------------------------------------------------------
|
| The ports are read through the container, so every case here also proves the
| provider binds them. The lock mode (`sharedLock()`) compiles to nothing on the
| SQLite stand-in; it is a source construction, scanned by the host's journal
| rules (step E1), and its engine behaviour is `[ENGINE]`, NOT VERIFIED.
|
| Mutations (restored byte for byte):
|   M12 `JournalAccountUsage` counts only `posted` entries -> the "any status" case goes red.
|   M13 `JournalPeriodCloseBlockers` drops the `draft` filter -> the "drafts only" case goes red.
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
 * One line written raw into a raw entry, the way a draft (which nothing in this task can create) would look.
 */
function prtRawLine(string $entryId, string $accountId, int $lineNo = 1): void
{
    FinanceSeed::connection()->table('journal_lines')->insert([
        'id' => (string) Str::ulid(),
        'entry_id' => $entryId,
        'line_no' => $lineNo,
        'account_id' => $accountId,
        'debit' => '5.0000',
        'credit' => '0.0000',
        'memo' => null,
        'created_at' => FinanceSeed::NOW,
    ]);
}

// ------------------------------------------------------------------ binding

it('binds the three ports to the journal classes, and the T-032 defaults stay constructible (AC-62)', function () {
    expect(app(AccountUsage::class))->toBeInstanceOf(JournalAccountUsage::class)
        ->and(app(FiscalYearUsage::class))->toBeInstanceOf(JournalFiscalYearUsage::class)
        ->and(app(PeriodCloseBlockers::class))->toBeInstanceOf(JournalPeriodCloseBlockers::class)
        ->and(app()->make(NoAccountUsage::class)->hasPostings((string) Str::ulid()))->toBeFalse();
});

// ------------------------------------------------------------------ AC-59

it('answers hasPostings from any entry status, false for no line, an unknown id and a malformed one (AC-59, M12)', function () {
    $world = jrnWorld();
    $usage = app(AccountUsage::class);

    $posted = jrnPost(jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => '10'],
        ['account_id' => $world['sales'], 'credit' => '10'],
    ]]), $world['actor']);

    // `sales` carries a line of a posted entry, then of a reversed one (the original flips to reversed).
    jrnReverse($posted->id, '2026-03-20', 'undo', $world['actor']);

    expect(jrnEntry($posted->id)['status'])->toBe('reversed')
        ->and($usage->hasPostings($world['cash']))->toBeTrue()
        ->and($usage->hasPostings($world['sales']))->toBeTrue();

    // An account whose only line sits in a draft entry still has postings: the first rule is "any status".
    $drafted = FinanceSeed::account('1900');
    $draft = jrnRawEntry($world['periods'][3], ['status' => 'draft']);
    prtRawLine($draft['id'], $drafted);

    expect($usage->hasPostings($drafted))->toBeTrue()
        ->and($usage->hasPostings($world['other']))->toBeFalse()
        ->and($usage->hasPostings((string) Str::ulid()))->toBeFalse()
        ->and($usage->hasPostings('not-an-id'))->toBeFalse()
        ->and($usage->hasPostings(''))->toBeFalse();
});

it('answers hasPostings only for a reversed entry\'s account when that is the only entry (AC-59)', function () {
    $world = jrnWorld();
    $reversed = jrnRawEntry($world['periods'][3], ['status' => 'reversed']);
    $account = FinanceSeed::account('1901');
    prtRawLine($reversed['id'], $account);

    expect(app(AccountUsage::class)->hasPostings($account))->toBeTrue();
});

// ------------------------------------------------------------------ AC-60

it('answers hasEntries over the inclusive range for any status, ignoring the year id (AC-60)', function () {
    $world = jrnWorld();
    $usage = app(FiscalYearUsage::class);
    $anyYear = (string) Str::ulid();

    expect($usage->hasEntries($world['year'], '2026-01-01', '2026-12-31'))->toBeFalse();

    jrnRawEntry($world['periods'][3], ['entry_date' => '2026-03-15', 'status' => 'draft']);

    expect($usage->hasEntries($world['year'], '2026-01-01', '2026-12-31'))->toBeTrue()
        ->and($usage->hasEntries($anyYear, '2026-01-01', '2026-12-31'))->toBeTrue('the year id is not consulted')
        ->and($usage->hasEntries($world['year'], '2026-03-15', '2026-03-15'))->toBeTrue('both ends of a one-day range count')
        ->and($usage->hasEntries($world['year'], '2026-03-01', '2026-03-15'))->toBeTrue('the last day counts')
        ->and($usage->hasEntries($world['year'], '2026-03-15', '2026-03-31'))->toBeTrue('the first day counts')
        ->and($usage->hasEntries($world['year'], '2026-03-16', '2026-12-31'))->toBeFalse('the day after')
        ->and($usage->hasEntries($world['year'], '2026-01-01', '2026-03-14'))->toBeFalse('the day before');

    foreach (['posted', 'reversed'] as $status) {
        jrnRawEntry($world['periods'][6], ['entry_date' => '2026-06-10', 'status' => $status]);

        expect($usage->hasEntries($world['year'], '2026-06-10', '2026-06-10'))->toBeTrue($status);

        FinanceSeed::connection()->table('journal_entries')->where('entry_date', '2026-06-10')->delete();
    }
});

// ------------------------------------------------------------------ AC-61

it('reports draft entries in the range as one blocker, and nothing else blocks (AC-61, M13)', function () {
    $world = jrnWorld();
    $blockers = app(PeriodCloseBlockers::class);
    $period = $world['periods'][3];

    expect($blockers->blockersFor($period, '2026-03-01', '2026-03-31'))->toBe([]);

    // Posted and reversed entries in the range never block.
    jrnRawEntry($period, ['entry_date' => '2026-03-10', 'status' => 'posted']);
    jrnRawEntry($period, ['entry_date' => '2026-03-11', 'status' => 'reversed']);

    expect($blockers->blockersFor($period, '2026-03-01', '2026-03-31'))->toBe([]);

    jrnRawEntry($period, ['entry_date' => '2026-03-01', 'status' => 'draft']);
    jrnRawEntry($period, ['entry_date' => '2026-03-31', 'status' => 'draft']);
    jrnRawEntry($period, ['entry_date' => '2026-03-15', 'status' => 'draft']);
    jrnRawEntry($world['periods'][4], ['entry_date' => '2026-04-01', 'status' => 'draft']);

    $found = $blockers->blockersFor($period, '2026-03-01', '2026-03-31');

    expect($found)->toHaveCount(1)
        ->and($found[0])->toBeInstanceOf(PeriodBlocker::class)
        ->and($found[0]->kind)->toBe('draft_journal_entries')
        ->and($found[0]->count)->toBe(3, 'both ends of the range count, the next month does not')
        ->and($blockers->blockersFor($period, '2026-03-02', '2026-03-30'))->toHaveCount(1)
        ->and($blockers->blockersFor($period, '2026-03-02', '2026-03-30')[0]->count)->toBe(1)
        ->and($blockers->blockersFor($period, '2026-05-01', '2026-05-31'))->toBe([]);
});

// ------------------------------------------------------------------ AC-62: T-032's guards, over real bindings

it('lets T-032 refuse each change to an account that has a line, over the real bindings (AC-62)', function () {
    $world = jrnWorld();
    $actor = $world['actor'];

    // Four postable, non-system, uncontrolled accounts, each with a line, so each rule is reached by itself.
    $recode = $world['cash'];
    $delete = FinanceSeed::account('2001');
    $header = FinanceSeed::account('2002');
    $retype = FinanceSeed::account('2003');
    $resubtype = FinanceSeed::account('2004', ['type' => 'expense', 'subtype' => 'cogs', 'normal_balance' => 'debit']);
    $control = FinanceSeed::account('2005', ['subtype' => 'cash']);

    $lines = [];

    foreach ([$recode, $delete, $header, $retype, $resubtype, $control] as $account) {
        $lines[] = ['account_id' => $account, 'debit' => '1'];
    }

    $lines[] = ['account_id' => $world['sales'], 'credit' => '6'];

    jrnPost(jrnBody($world, ['lines' => $lines]), $actor);

    $update = static fn (string $id, array $fields) => app(UpdateAccountHandler::class)->handle(new UpdateAccount($id, $fields, $actor));
    $before = FinanceSeed::snapshot();

    FinanceRefusals::expect(FinanceRefusal::CodeLocked, fn () => $update($recode, ['code' => 'ZZ9']));
    FinanceRefusals::expect(FinanceRefusal::HasPostings, fn () => app(DeleteAccountHandler::class)->handle(new DeleteAccount($delete, $actor)));
    FinanceRefusals::expect(FinanceRefusal::HasPostings, fn () => $update($header, ['is_postable' => false]));

    $retyped = FinanceRefusals::expect(FinanceRefusal::TypeChangeRefused, fn () => $update($retype, ['type' => 'liability', 'subtype' => null]));
    $resubtyped = FinanceRefusals::expect(FinanceRefusal::TypeChangeRefused, fn () => $update($resubtype, ['subtype' => 'operating_expense']));

    FinanceRefusals::expect(FinanceRefusal::ControlTypeLocked, fn () => $update($control, ['control_type' => 'cash']));

    expect($retyped->context)->toBe(['condition' => 'has_postings'])
        ->and($resubtyped->field)->toBe('subtype')
        ->and($resubtyped->context)->toBe(['condition' => 'has_postings'])
        ->and(FinanceSeed::snapshot())->toBe($before, 'every refusal left the chart untouched');
});

it('refuses to delete a year that holds an entry, and to close a period that holds a draft, over the real bindings (AC-62)', function () {
    $world = jrnWorld();
    $actor = $world['actor'];

    jrnPost(jrnBody($world, ['entry_date' => '2026-01-15']), $actor);

    $before = FinanceSeed::calendarSnapshot();

    $refused = FinanceRefusals::expect(FinanceRefusal::FiscalYearInUse, fn () => app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($world['year'], $actor)));

    expect($refused->context)->not->toBe([])
        ->and(FinanceSeed::calendarSnapshot())->toBe($before);

    // The month with only a posted entry soft-closes: a posted entry is not a blocker.
    $soft = static fn (string $period) => app(TransitionFiscalPeriodHandler::class)->handle(
        new TransitionFiscalPeriod($period, ['status' => 'soft_closed', 'expected_status' => 'open'], $actor),
    );

    $soft($world['periods'][1]);

    expect(FinanceSeed::periodRow($world['periods'][1])['status'])->toBe('soft_closed');

    // A raw draft in the next month blocks that month's soft close, and the refusal names the kind.
    jrnRawEntry($world['periods'][2], ['entry_date' => '2026-02-10', 'status' => 'draft']);

    $close = FinanceRefusals::expect(FinanceRefusal::CloseBlocked, fn () => $soft($world['periods'][2]));

    expect($close->context)->toBe(['blockers' => ['draft_journal_entries' => 1]])
        ->and(FinanceSeed::periodRow($world['periods'][2])['status'])->toBe('open');
});

it('still deletes an unused account and year over the real bindings (AC-62 control)', function () {
    $world = jrnWorld();
    $actor = $world['actor'];
    $unused = FinanceSeed::account('2999', ['is_active' => false]);

    app(DeleteAccountHandler::class)->handle(new DeleteAccount($unused, $actor));

    expect(FinanceSeed::connection()->table('accounts')->where('id', $unused)->value('deleted_at'))->not->toBeNull();

    app(DeleteFiscalYearHandler::class)->handle(new DeleteFiscalYear($world['year'], $actor));

    expect(FinanceSeed::yearRow($world['year']))->toBeNull();
});

// ------------------------------------------------------------------ AC-63

it('reads journal tables only, and returns primitives or PeriodBlocker values (AC-63)', function () {
    $world = jrnWorld();
    jrnRawEntry($world['periods'][3], ['entry_date' => '2026-03-15', 'status' => 'draft']);

    [$statements] = jrnStatements(function () use ($world): void {
        expect(app(AccountUsage::class)->hasPostings($world['cash']))->toBeBool()
            ->and(app(FiscalYearUsage::class)->hasEntries($world['year'], '2026-01-01', '2026-12-31'))->toBeBool()
            ->and(app(PeriodCloseBlockers::class)->blockersFor($world['periods'][3], '2026-03-01', '2026-03-31'))->each->toBeInstanceOf(PeriodBlocker::class);
    });

    expect($statements)->toHaveCount(3);

    foreach ($statements as $sql) {
        expect(preg_match('/\bjournal_(?:lines|entries)\b/', $sql))->toBe(1, $sql)
            ->and(preg_match('/\b(?:accounts|fiscal_years|fiscal_periods|employees|branches)\b/', $sql))->toBe(0, $sql);
    }
});

// ------------------------------------------------------------------ AC-64

it('answers hasPostings from the (account_id, entry_id) index, never a scan of the lines (AC-64)', function () {
    $world = jrnWorld();
    $entry = jrnRawEntry($world['periods'][3]);

    foreach (range(1, 30) as $lineNo) {
        prtRawLine($entry['id'], $world['cash'], $lineNo);
    }

    $captured = [];

    FinanceSeed::connection()->listen(static function ($query) use (&$captured): void {
        $captured[] = [$query->sql, $query->bindings];
    });

    expect(app(AccountUsage::class)->hasPostings($world['cash']))->toBeTrue()
        ->and($captured)->toHaveCount(1);

    [$sql, $bindings] = $captured[0];

    $plan = implode("\n", array_map(
        static fn (object $row): string => (string) $row->detail,
        FinanceSeed::connection()->select('explain query plan '.$sql, $bindings),
    ));

    expect($plan)->toContain('journal_lines_account_id_entry_id_index')
        ->and($plan)->not->toMatch('/\bSCAN journal_lines\b/');
});

// ------------------------------------------------------------------ AC-79

it('answers each tenant from its own journal: a posting in one is invisible to the other (AC-79)', function () {
    $world = jrnWorld();

    jrnPost(jrnBody($world), $world['actor']);

    expect(app(AccountUsage::class)->hasPostings($world['cash']))->toBeTrue()
        ->and(app(FiscalYearUsage::class)->hasEntries($world['year'], '2026-01-01', '2026-12-31'))->toBeTrue();

    AccessTenantFixture::switchTo('tenant-b');
    FinanceSeed::clearSeed();

    $other = jrnWorld();

    expect(FinanceSeed::connection()->table('journal_entries')->count())->toBe(0)
        ->and(FinanceSeed::connection()->table('journal_lines')->count())->toBe(0)
        ->and((int) FinanceSeed::connection()->table('number_sequences')->where('document_type', 'journal_entry')->value('next_number'))->toBe(1)
        ->and(app(AccountUsage::class)->hasPostings($world['cash']))->toBeFalse()
        ->and(app(AccountUsage::class)->hasPostings($other['cash']))->toBeFalse()
        ->and(app(FiscalYearUsage::class)->hasEntries($other['year'], '2026-01-01', '2026-12-31'))->toBeFalse();

    $posted = jrnPost(jrnBody($other), $other['actor']);

    expect($posted->number)->toBe('JE-000001');

    AccessTenantFixture::switchTo('tenant-a');

    expect(FinanceSeed::connection()->table('journal_entries')->count())->toBe(1)
        ->and((int) FinanceSeed::connection()->table('number_sequences')->where('document_type', 'journal_entry')->value('next_number'))->toBe(2);

    foreach (['journal_entries', 'journal_lines'] as $table) {
        $columns = FinanceSeed::connection()->getSchemaBuilder()->getColumnListing($table);

        expect(array_filter($columns, static fn (string $column): bool => str_contains($column, 'tenant')))->toBe([], $table);
    }
});

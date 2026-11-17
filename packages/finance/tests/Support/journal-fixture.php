<?php

use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Contracts\Finance\PeriodGate;
use Flexipos\Contracts\Finance\PostedEntry;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Contracts\Finance\ReversalRequest;
use Flexipos\Finance\Accounts\DatabaseChartOfAccounts;
use Flexipos\Finance\Periods\DatabasePeriodGate;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Finance\Tests\Support\RecordingChartOfAccounts;
use Flexipos\Finance\Tests\Support\RecordingPeriodGate;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| File-scope helpers shared by the posting-service tests (T-033 chunk C)
|--------------------------------------------------------------------------
|
| `require_once`d next to `finance-fixture.php`. Every function name starts
| `jrn` so it cannot collide with another file's helpers (Pest declares them
| globally). The posting service runs inside the caller's transaction, so
| every call here opens one on the fixture connection, the way a document
| handler does.
*/

require_once __DIR__.'/finance-fixture.php';

/**
 * A year of open periods, two postable accounts and an actor who is a live employee.
 *
 * @return array{periods: array<int, string>, year: string, cash: string, sales: string, other: string, actor: string}
 */
function jrnWorld(string $yearStart = '2026-01-01'): array
{
    $year = FinanceSeed::fiscalYear($yearStart);

    return [
        'year' => $year['id'],
        'periods' => $year['periods'],
        'cash' => FinanceSeed::account('1000'),
        'sales' => FinanceSeed::account('4000', ['type' => 'revenue', 'normal_balance' => 'credit']),
        'other' => FinanceSeed::account('4100', ['type' => 'revenue', 'normal_balance' => 'credit']),
        'actor' => FinanceSeed::admin(),
    ];
}

/**
 * A valid two-line `sale` body: cash debited and sales credited with `100.00`.
 *
 * @param  array{cash: string, sales: string}  $world
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function jrnBody(array $world, array $over = []): array
{
    return array_merge([
        'source_type' => 'sale',
        'source_id' => (string) Str::ulid(),
        'entry_date' => '2026-03-15',
        'description' => 'A sale',
        'lines' => [
            ['account_id' => $world['cash'], 'debit' => '100.00', 'memo' => 'cash in'],
            ['account_id' => $world['sales'], 'credit' => '100.00'],
        ],
    ], $over);
}

/**
 * Run `$work` inside a transaction on the fixture connection.
 *
 * @param  Closure(): mixed  $work
 */
function jrnInTransaction(Closure $work): mixed
{
    return FinanceSeed::connection()->transaction($work);
}

/**
 * @param  array<string, mixed>  $body
 */
function jrnPost(array $body, ?string $actor = null): PostedEntry
{
    return jrnInTransaction(fn (): PostedEntry => app(PostingService::class)->post(PostingRequest::fromArray($body, $actor)));
}

function jrnReverse(string $entryId, string $date, mixed $reason, ?string $actor = null): PostedEntry
{
    return jrnInTransaction(fn (): PostedEntry => app(PostingService::class)->reverse(
        ReversalRequest::fromArray(['entry_id' => $entryId, 'entry_date' => $date, 'reason' => $reason], $actor),
    ));
}

/**
 * The refusal a `post` raises, or null when it succeeds.
 *
 * @param  array<string, mixed>  $body
 */
function jrnPostRefusal(array $body, ?string $actor = null): ?PostingRefused
{
    try {
        jrnPost($body, $actor);
    } catch (PostingRefused $refused) {
        return $refused;
    }

    return null;
}

function jrnReverseRefusal(string $entryId, string $date, mixed $reason, ?string $actor = null): ?PostingRefused
{
    try {
        jrnReverse($entryId, $date, $reason, $actor);
    } catch (PostingRefused $refused) {
        return $refused;
    }

    return null;
}

/**
 * The persistent state a refusal must leave untouched: entry, line and audit
 * counts, and the next number of the journal sequence.
 *
 * @return array{entries: int, lines: int, audit: int, next: int}
 */
function jrnState(): array
{
    $connection = FinanceSeed::connection();

    return [
        'entries' => $connection->table('journal_entries')->count(),
        'lines' => $connection->table('journal_lines')->count(),
        'audit' => $connection->table('audit_logs')->count(),
        'next' => (int) $connection->table('number_sequences')->where('document_type', 'journal_entry')->value('next_number'),
    ];
}

/**
 * The statements `$work` sends to the fixture connection, in order.
 *
 * @return array{0: list<string>, 1: ?Throwable}
 */
function jrnStatements(callable $work): array
{
    $recorder = new class
    {
        public bool $active = true;

        /** @var list<string> */
        public array $log = [];
    };

    FinanceSeed::connection()->listen(function ($query) use ($recorder): void {
        if ($recorder->active) {
            $recorder->log[] = $query->sql;
        }
    });

    $thrown = null;

    try {
        $work();
    } catch (Throwable $caught) {
        $thrown = $caught;
    } finally {
        $recorder->active = false;
    }

    return [$recorder->log, $thrown];
}

/**
 * Like {@see jrnStatements()}, for a call that may only end in a refusal: any
 * other throwable is rethrown, so the caller reads `->reason` on a typed value.
 *
 * @return array{0: list<string>, 1: ?PostingRefused}
 */
function jrnRefusalStatements(callable $work): array
{
    [$statements, $thrown] = jrnStatements($work);

    if ($thrown !== null && ! $thrown instanceof PostingRefused) {
        throw $thrown;
    }

    return [$statements, $thrown];
}

/**
 * Statements that name a table, by a case-insensitive word match.
 *
 * @param  list<string>  $statements
 * @return list<string>
 */
function jrnStatementsOn(array $statements, string $table): array
{
    return array_values(array_filter(
        $statements,
        static fn (string $sql): bool => preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql) === 1,
    ));
}

/**
 * Every stored entry row by number, with lines.
 *
 * @return array<string, mixed>|null
 */
function jrnEntry(string $id): ?array
{
    $row = FinanceSeed::connection()->table('journal_entries')->where('id', $id)->first();

    return $row === null ? null : (array) $row;
}

/**
 * @return list<array<string, mixed>>
 */
function jrnLines(string $entryId): array
{
    return FinanceSeed::connection()->table('journal_lines')->where('entry_id', $entryId)->orderBy('line_no')->get()
        ->map(static fn (object $row): array => (array) $row)->all();
}

/**
 * Bind a spy over the real gate. `$before` runs inside each `clear()` call.
 */
function jrnSpyOnGate(?Closure $before = null): RecordingPeriodGate
{
    $spy = new RecordingPeriodGate(app()->make(DatabasePeriodGate::class), $before);
    app()->instance(PeriodGate::class, $spy);

    return $spy;
}

/**
 * Bind a spy over the real chart of accounts.
 */
function jrnSpyOnChart(): RecordingChartOfAccounts
{
    $spy = new RecordingChartOfAccounts(app()->make(DatabaseChartOfAccounts::class));
    app()->instance(ChartOfAccounts::class, $spy);

    return $spy;
}

/**
 * One entry row written raw, the way a competing transaction's commit would
 * look to a post that lost the race. Tests only: the application's one writer
 * is `JournalWriter` (T-033 AC-5).
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed> the row as inserted
 */
function jrnRawEntry(string $periodId, array $over = []): array
{
    $row = array_merge([
        'id' => (string) Str::ulid(),
        'number' => 'RAW-'.Str::random(6),
        'entry_date' => '2026-03-15',
        'period_id' => $periodId,
        'branch_id' => null,
        'source_type' => 'sale',
        'source_id' => null,
        'source_purpose' => null,
        'status' => 'posted',
        'total_debit' => '1.0000',
        'total_credit' => '1.0000',
        'description' => null,
        'reversal_of_id' => null,
        'reversed_by_id' => null,
        'payload_fingerprint' => str_repeat('0', 64),
        'posted_at' => FinanceSeed::NOW,
        'posted_by' => null,
        'is_auto' => true,
        'created_by' => null,
        'created_at' => FinanceSeed::NOW,
        'updated_at' => FinanceSeed::NOW,
    ], $over);

    FinanceSeed::connection()->table('journal_entries')->insert($row);

    return $row;
}

/**
 * A row in a table this package does not own, with the columns the table
 * insists on and nothing else. Foreign keys are switched off for the insert
 * only (their targets are other modules' tables, which this test has no
 * business building), then back on, so the journal's own keys stay enforced.
 *
 * @param  array<string, mixed>  $over
 */
function jrnForeignRow(string $table, array $over = []): string
{
    $id = (string) Str::ulid();
    $unique = strtoupper(Str::random(8));

    $rows = [
        'locations' => ['id' => $id, 'code' => substr($unique, 0, 8), 'name' => 'L '.$unique, 'type' => 'store', 'created_at' => FinanceSeed::NOW, 'updated_at' => FinanceSeed::NOW],
        'customers' => ['id' => $id, 'name' => 'C '.$unique, 'created_at' => FinanceSeed::NOW, 'updated_at' => FinanceSeed::NOW],
        'products' => [
            'id' => $id, 'type' => 'simple', 'sku' => $unique, 'name' => 'P '.$unique,
            'category_id' => (string) Str::ulid(), 'stock_unit_id' => (string) Str::ulid(),
            'created_at' => FinanceSeed::NOW, 'updated_at' => FinanceSeed::NOW,
        ],
    ];

    $connection = FinanceSeed::connection();
    $connection->statement('pragma foreign_keys = off');

    try {
        $connection->table($table)->insert(array_merge($rows[$table], $over));
    } finally {
        $connection->statement('pragma foreign_keys = on');
    }

    return (string) ($over['id'] ?? $id);
}

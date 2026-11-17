<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Journal;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\JournalEntryStatus;
use Flexipos\Contracts\Finance\PostingLine;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Contracts\Finance\ReversalRequest;
use Flexipos\Finance\Journal\Amounts;
use Flexipos\Finance\Journal\DatabasePostingService;
use Flexipos\Finance\Journal\JournalWriter;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step C1 - `post` on the database (happy path, numbering, audit)
|--------------------------------------------------------------------------
|
| Stand-in through `AccessTenantFixture`. The stand-in ignores row locks and
| enforces no CHECK, so what is proved here is the order of statements, the
| rows written and the rollback behaviour; every lock claim is `[ENGINE]`,
| NOT VERIFIED under ENGINE_HOLD.
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
 * A row standing in for the caller's own document, so a rollback has something to take with it.
 */
function postScratchDocument(): void
{
    FinanceSeed::connection()->table('number_sequences')->insert([
        'id' => (string) Str::ulid(),
        'branch_id' => null,
        'document_type' => 'scratch_doc',
        'prefix' => null,
        'next_number' => 1,
        'padding' => 0,
        'suffix' => null,
        'reset_period' => 'never',
        'last_reset_at' => null,
        'created_at' => FinanceSeed::NOW,
        'updated_at' => FinanceSeed::NOW,
    ]);
}

function postScratchDocumentCount(): int
{
    return FinanceSeed::connection()->table('number_sequences')->where('document_type', 'scratch_doc')->count();
}

it('is bound to the database posting service (Ruling 1)', function () {
    expect(app(PostingService::class))->toBeInstanceOf(DatabasePostingService::class);
});

// ------------------------------------------------------------------ AC-2

it('throws LogicException outside a transaction and sends no statement, for post and reverse (AC-2)', function () {
    $world = jrnWorld();
    $service = app(PostingService::class);

    expect(FinanceSeed::connection()->transactionLevel())->toBe(0);

    [$statements, $thrown] = jrnStatements(fn () => $service->post(PostingRequest::fromArray(jrnBody($world), null)));

    expect($thrown)->toBeInstanceOf(LogicException::class)
        ->and($statements)->toBe([]);

    [$statements, $thrown] = jrnStatements(fn () => $service->reverse(
        ReversalRequest::fromArray(['entry_id' => (string) Str::ulid(), 'entry_date' => '2026-03-15', 'reason' => 'x'], null),
    ));

    expect($thrown)->toBeInstanceOf(LogicException::class)
        ->and($statements)->toBe([]);

    // Even a request that would be refused for its shape is a LogicException first, and sends nothing.
    [$statements, $thrown] = jrnStatements(fn () => $service->post(PostingRequest::fromArray(['source_type' => 'nope'], 'bad')));

    expect($thrown)->toBeInstanceOf(LogicException::class)
        ->and($statements)->toBe([])
        ->and(jrnState()['entries'])->toBe(0);
});

// ------------------------------------------------------------------ AC-3

it('rolls the caller\'s document back with a later throw and with a refused post (AC-3)', function () {
    $world = jrnWorld();
    $before = jrnState();

    try {
        jrnInTransaction(function () use ($world): void {
            postScratchDocument();
            app(PostingService::class)->post(PostingRequest::fromArray(jrnBody($world), $world['actor']));

            throw new RuntimeException('a later statement failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(postScratchDocumentCount())->toBe(0)
        ->and(jrnState())->toBe($before);

    $refused = null;

    try {
        jrnInTransaction(function () use ($world): void {
            postScratchDocument();
            app(PostingService::class)->post(PostingRequest::fromArray(
                jrnBody($world, ['lines' => [['account_id' => $world['cash'], 'debit' => '5'], ['account_id' => $world['sales'], 'credit' => '4']]]),
                $world['actor'],
            ));
        });
    } catch (PostingRefused $refused) {
        // expected
    }

    expect($refused)->toBeInstanceOf(PostingRefused::class)
        ->and($refused->reason)->toBe(PostingRefusal::EntryUnbalanced)
        ->and(postScratchDocumentCount())->toBe(0)
        ->and(jrnState())->toBe($before);

    // And a committed document keeps its entry: the pair commits together.
    jrnInTransaction(function () use ($world): void {
        postScratchDocument();
        app(PostingService::class)->post(PostingRequest::fromArray(jrnBody($world), $world['actor']));
    });

    expect(postScratchDocumentCount())->toBe(1)
        ->and(jrnState()['entries'])->toBe(1);
});

// ------------------------------------------------------------------ AC-22, AC-42, AC-43, AC-74

it('stores the entry row whole, from the fixed clock, with lines 1 to N in request order (AC-22, AC-42, AC-43)', function () {
    $world = jrnWorld();
    $branch = AccessSeed::branch();
    $sourceId = (string) Str::ulid();

    $entry = jrnPost(jrnBody($world, [
        'source_id' => strtolower($sourceId),
        'source_purpose' => 'revenue',
        'description' => '  Counter sale  ',
        'branch_id' => strtolower($branch),
        'lines' => [
            ['account_id' => $world['sales'], 'credit' => '12.5'],
            ['account_id' => $world['cash'], 'debit' => '12.5', 'memo' => '  till  '],
        ],
    ]), $world['actor']);

    expect($entry->number)->toBe('JE-000001')
        ->and($entry->periodId)->toBe($world['periods'][3])
        ->and($entry->entryDate)->toBe('2026-03-15')
        ->and($entry->totalDebit)->toBe('12.5000')
        ->and($entry->totalCredit)->toBe('12.5000')
        ->and($entry->status)->toBe(JournalEntryStatus::Posted)
        ->and($entry->replayed)->toBeFalse()
        ->and(Str::isUlid($entry->id))->toBeTrue()
        ->and($entry->id)->toBe(strtoupper($entry->id));

    $row = jrnEntry($entry->id);

    expect($row)->not->toBeNull();

    $row['total_debit'] = Amounts::fromStored($row['total_debit']);
    $row['total_credit'] = Amounts::fromStored($row['total_credit']);
    $row['is_auto'] = (bool) $row['is_auto'];
    unset($row['payload_fingerprint']);

    expect($row)->toBe([
        'id' => $entry->id,
        'number' => 'JE-000001',
        'entry_date' => '2026-03-15',
        'period_id' => $world['periods'][3],
        'branch_id' => $branch,
        'source_type' => 'sale',
        'source_id' => $sourceId,
        'source_purpose' => 'revenue',
        'source_purpose_key' => 'revenue',
        'status' => 'posted',
        'total_debit' => '12.5000',
        'total_credit' => '12.5000',
        'description' => 'Counter sale',
        'reversal_of_id' => null,
        'reversed_by_id' => null,
        'posted_at' => '2026-11-10 10:00:00.000',
        'posted_by' => $world['actor'],
        'is_auto' => false,
        'created_by' => $world['actor'],
        'created_at' => '2026-11-10 10:00:00.000',
        'updated_at' => '2026-11-10 10:00:00.000',
    ]);

    expect(strlen((string) jrnEntry($entry->id)['payload_fingerprint']))->toBe(64);

    $lines = array_map(static function (array $line): array {
        unset($line['id']);
        $line['debit'] = Amounts::fromStored($line['debit']);
        $line['credit'] = Amounts::fromStored($line['credit']);

        return $line;
    }, jrnLines($entry->id));

    $dimensions = array_fill_keys(['branch_id', 'location_id', 'department_id', 'employee_id', 'customer_id', 'supplier_id', 'product_id'], null);

    expect($lines)->toBe([
        array_merge(['entry_id' => $entry->id, 'line_no' => 1, 'account_id' => $world['sales'], 'debit' => '0.0000', 'credit' => '12.5000', 'memo' => null], $dimensions, ['created_at' => '2026-11-10 10:00:00.000']),
        array_merge(['entry_id' => $entry->id, 'line_no' => 2, 'account_id' => $world['cash'], 'debit' => '12.5000', 'credit' => '0.0000', 'memo' => 'till'], $dimensions, ['created_at' => '2026-11-10 10:00:00.000']),
    ]);
});

it('marks a null-actor posting is_auto with a null posted_by, and audits it with a null actor (AC-74)', function () {
    $world = jrnWorld();

    $entry = jrnPost(jrnBody($world), null);
    $row = jrnEntry($entry->id);
    $audit = FinanceSeed::onlyAuditRow();

    expect($row['posted_by'])->toBeNull()
        ->and($row['created_by'])->toBeNull()
        ->and((bool) $row['is_auto'])->toBeTrue()
        ->and($audit['actor_id'])->toBeNull()
        ->and($audit['after']['is_auto'])->toBeTrue();
});

it('stores a posting with an actor as not is_auto (AC-74)', function () {
    $world = jrnWorld();

    $entry = jrnPost(jrnBody($world), $world['actor']);

    expect((bool) jrnEntry($entry->id)['is_auto'])->toBeFalse()
        ->and(jrnEntry($entry->id)['posted_by'])->toBe($world['actor']);
});

// ------------------------------------------------------------------ AC-24

it('accepts one account on several lines, debit and credit alike, and stores each line separately (AC-24)', function () {
    $world = jrnWorld();

    $entry = jrnPost(jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => '30'],
        ['account_id' => $world['cash'], 'debit' => '20'],
        ['account_id' => $world['cash'], 'credit' => '5'],
        ['account_id' => $world['sales'], 'credit' => '45'],
    ]]));

    $lines = jrnLines($entry->id);

    expect($lines)->toHaveCount(4)
        ->and(array_column($lines, 'line_no'))->toBe([1, 2, 3, 4])
        ->and(array_column($lines, 'account_id'))->toBe([$world['cash'], $world['cash'], $world['cash'], $world['sales']])
        ->and($entry->totalDebit)->toBe('50.0000');
});

// ------------------------------------------------------------------ AC-32, AC-33

it('numbers entries JE-000001, JE-000002 from the company row even with a branch set (AC-32, AC-33)', function () {
    $world = jrnWorld();
    $branch = AccessSeed::branch();

    $first = jrnPost(jrnBody($world, ['branch_id' => $branch]));
    $second = jrnPost(jrnBody($world));
    $third = jrnPost(jrnBody($world, ['branch_id' => $branch]));

    expect([$first->number, $second->number, $third->number])->toBe(['JE-000001', 'JE-000002', 'JE-000003'])
        ->and(jrnState()['next'])->toBe(4)
        ->and(FinanceSeed::connection()->table('number_sequences')->where('document_type', 'journal_entry')->count())->toBe(1)
        ->and(FinanceSeed::connection()->table('number_sequences')->where('document_type', 'journal_entry')->value('branch_id'))->toBeNull();
});

it('leaves the sequence, the entries and the audit untouched for one refusal of every family and for a replay (AC-33, AC-66)', function (string $family) {
    $world = jrnWorld();
    $stored = jrnBody($world, ['source_id' => (string) Str::ulid()]);
    jrnPost($stored);

    $before = jrnState();

    $refusal = match ($family) {
        'shape' => jrnPostRefusal(jrnBody($world, ['source_type' => 'nope'])),
        'amount' => jrnPostRefusal(jrnBody($world, ['lines' => [['account_id' => $world['cash'], 'debit' => '1.00001'], ['account_id' => $world['sales'], 'credit' => '1']]])),
        'balance' => jrnPostRefusal(jrnBody($world, ['lines' => [['account_id' => $world['cash'], 'debit' => '2'], ['account_id' => $world['sales'], 'credit' => '1']]])),
        'actor' => jrnPostRefusal(jrnBody($world), 'not-an-employee'),
        'conflict' => jrnPostRefusal(array_merge($stored, ['description' => 'different'])),
        'gate' => jrnPostRefusal(jrnBody($world, ['entry_date' => '2031-01-01'])),
        'account' => jrnPostRefusal(jrnBody($world, ['lines' => [['account_id' => (string) Str::ulid(), 'debit' => '1'], ['account_id' => $world['sales'], 'credit' => '1']]])),
        'reference' => jrnPostRefusal(jrnBody($world, ['branch_id' => (string) Str::ulid()])),
        'replay' => null,
        default => throw new \InvalidArgumentException($family),
    };

    if ($family === 'replay') {
        expect(jrnPost($stored)->replayed)->toBeTrue();
    } else {
        expect($refusal)->toBeInstanceOf(PostingRefused::class);
    }

    expect(jrnState())->toBe($before);
})->with(['shape', 'amount', 'balance', 'actor', 'conflict', 'gate', 'account', 'reference', 'replay']);

// ------------------------------------------------------------------ AC-46

it('inserts all the lines in exactly one statement (AC-46, M16)', function () {
    $world = jrnWorld();

    $lines = [];

    for ($i = 0; $i < 5; $i++) {
        $lines[] = ['account_id' => $world['cash'], 'debit' => '1'];
    }

    $lines[] = ['account_id' => $world['sales'], 'credit' => '5'];

    [$statements, $thrown] = jrnStatements(fn () => jrnPost(jrnBody($world, ['lines' => $lines])));

    expect($thrown)->toBeNull();

    $inserts = array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/\Ainsert into "journal_lines"/i', $sql) === 1));
    $entryInserts = array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/\Ainsert into "journal_entries"/i', $sql) === 1));

    expect($inserts)->toHaveCount(1)
        ->and($entryInserts)->toHaveCount(1)
        ->and(jrnState()['lines'])->toBe(6);
});

it('leaves neither the entry nor a line, and the sequence unchanged, when the line insert fails (AC-46)', function () {
    $world = jrnWorld();

    app()->bind(JournalWriter::class, fn ($app) => new class($app->make(TenantConnection::class)) extends JournalWriter
    {
        public function insertLines(array $rows): void
        {
            throw new RuntimeException('forced line-insert failure');
        }
    });

    $before = jrnState();

    $after = jrnInTransaction(function () use ($world): array {
        try {
            app(PostingService::class)->post(PostingRequest::fromArray(jrnBody($world), $world['actor']));
        } catch (RuntimeException $failure) {
            expect($failure->getMessage())->toBe('forced line-insert failure');
        }

        // Read inside the caller's transaction: the savepoint, not the caller, undid the entry.
        return jrnState();
    });

    expect($after)->toBe($before);
});

// ------------------------------------------------------------------ AC-65, AC-66

it('writes exactly one audit row, compared whole (AC-65)', function () {
    $world = jrnWorld();
    $sourceId = (string) Str::ulid();

    $entry = jrnPost(jrnBody($world, ['source_id' => $sourceId, 'source_purpose' => 'revenue']), $world['actor']);

    expect(FinanceSeed::onlyAuditRow())->toBe([
        'actor_type' => 'employee',
        'actor_id' => $world['actor'],
        'actor_name' => 'finance-admin',
        'branch_id' => null,
        'module' => 'finance',
        'action' => 'journal_entry_posted',
        'subject_type' => 'journal_entry',
        'subject_id' => $entry->id,
        'before' => null,
        'after' => [
            'number' => 'JE-000001',
            'source_type' => 'sale',
            'source_id' => $sourceId,
            'source_purpose' => 'revenue',
            'entry_date' => '2026-03-15',
            'period_id' => $world['periods'][3],
            'total_debit' => '100.0000',
            'total_credit' => '100.0000',
            'line_count' => 2,
            'is_auto' => false,
        ],
        'changed_fields' => ['entry_date', 'is_auto', 'line_count', 'number', 'period_id', 'source_id', 'source_purpose', 'source_type', 'total_credit', 'total_debit'],
        'user_agent' => null,
        'request_id' => null,
    ]);
});

it('writes no audit row for a refusal or a replay (AC-66)', function () {
    $world = jrnWorld();
    $body = jrnBody($world);

    jrnPost($body);
    $audit = jrnState()['audit'];

    expect(jrnPostRefusal(array_merge($body, ['description' => 'changed'])))->toBeInstanceOf(PostingRefused::class)
        ->and(jrnPost($body)->replayed)->toBeTrue()
        ->and(jrnState()['audit'])->toBe($audit);
});

// ------------------------------------------------------------------ AC-16 at the service

it('refuses a directly built line that names a dimension the contract does not define, before any read (AC-16)', function () {
    $world = jrnWorld();

    $request = new PostingRequest('sale', (string) Str::ulid(), null, '2026-03-15', null, null, [
        new PostingLine($world['cash'], '1', '0', null, ['project_ref' => 'x']),
        new PostingLine($world['sales'], '0', '1'),
    ], null);

    [$statements, $thrown] = jrnRefusalStatements(fn () => jrnInTransaction(fn () => app(PostingService::class)->post($request)));

    expect($thrown)->toBeInstanceOf(PostingRefused::class)
        ->and($thrown->reason)->toBe(PostingRefusal::UnknownField)
        ->and($thrown->fields)->toBe(['lines.0.project_ref'])
        ->and($statements)->toBe([]);
});

// ------------------------------------------------------------------ AC-73a

it('refuses a malformed actor with no statement at all, and a soft-deleted employee after the one actor read (AC-73a)', function () {
    $world = jrnWorld();
    $gone = AccessSeed::employee('gone', ['deleted_at' => '2026-01-01 00:00:00.000']);
    $before = jrnState();

    [$statements, $thrown] = jrnRefusalStatements(fn () => jrnPost(jrnBody($world), 'not-a-ulid'));

    expect($thrown)->toBeInstanceOf(PostingRefused::class)
        ->and($thrown->reason)->toBe(PostingRefusal::InvalidActor)
        ->and(jrnStatementsOn($statements, 'journal_entries'))->toBe([]);

    [$statements, $thrown] = jrnRefusalStatements(fn () => jrnPost(jrnBody($world), $gone));

    expect($thrown)->toBeInstanceOf(PostingRefused::class)
        ->and($thrown->reason)->toBe(PostingRefusal::InvalidActor)
        ->and($statements)->toHaveCount(1)
        ->and(jrnStatementsOn($statements, 'employees'))->toHaveCount(1)
        ->and(jrnState())->toBe($before);
});

it('refuses a malformed amount with a malformed actor as invalid_amount, and an unbalanced one as entry_unbalanced, with no statement (AC-73a)', function () {
    $world = jrnWorld();

    [$statements, $thrown] = jrnRefusalStatements(fn () => jrnPost(
        jrnBody($world, ['lines' => [['account_id' => $world['cash'], 'debit' => '1.00001'], ['account_id' => $world['sales'], 'credit' => '1']]]),
        'not-a-ulid',
    ));

    expect($thrown)->toBeInstanceOf(PostingRefused::class)
        ->and($thrown->reason)->toBe(PostingRefusal::InvalidAmount)
        ->and($statements)->toBe([]);

    [$statements, $thrown] = jrnRefusalStatements(fn () => jrnPost(
        jrnBody($world, ['lines' => [['account_id' => $world['cash'], 'debit' => '2'], ['account_id' => $world['sales'], 'credit' => '1']]]),
        'not-a-ulid',
    ));

    expect($thrown)->toBeInstanceOf(PostingRefused::class)
        ->and($thrown->reason)->toBe(PostingRefusal::EntryUnbalanced)
        ->and($statements)->toBe([]);
});

it('refuses an invalid actor on a key with a stored entry as invalid_actor and never reads journal_entries (AC-73a, D-38, M21)', function () {
    $world = jrnWorld();
    $gone = AccessSeed::employee('gone', ['deleted_at' => '2026-01-01 00:00:00.000']);
    $body = jrnBody($world);

    jrnPost($body, $world['actor']);

    foreach (['not-a-ulid', $gone] as $actor) {
        [$statements, $thrown] = jrnRefusalStatements(fn () => jrnPost($body, $actor));

        expect($thrown)->toBeInstanceOf(PostingRefused::class)
            ->and($thrown->reason)->toBe(PostingRefusal::InvalidActor)
            ->and(jrnStatementsOn($statements, 'journal_entries'))->toBe([]);
    }

    // The guard is not vacuous: a live actor on the same key does reach the replay read.
    [$statements, $thrown] = jrnRefusalStatements(fn () => jrnPost($body, $world['actor']));

    expect($thrown)->toBeNull()
        ->and(jrnStatementsOn($statements, 'journal_entries'))->not->toBe([]);
});

it('refuses an invalid actor on reverse after the shape checks and before any entry read (AC-73a, Ruling 8)', function () {
    $world = jrnWorld();
    $gone = AccessSeed::employee('gone', ['deleted_at' => '2026-01-01 00:00:00.000']);

    [$statements, $thrown] = jrnRefusalStatements(fn () => jrnReverse((string) Str::ulid(), '2026-03-15', 'why', $gone));

    expect($thrown)->toBeInstanceOf(PostingRefused::class)
        ->and($thrown->reason)->toBe(PostingRefusal::InvalidActor)
        ->and(jrnStatementsOn($statements, 'journal_entries'))->toBe([])
        ->and($world['actor'])->not->toBe('');

    // A malformed id with an invalid actor is entry_not_found: shape first.
    [, $thrown] = jrnRefusalStatements(fn () => jrnReverse('not-an-id', '2026-03-15', 'why', $gone));

    expect($thrown)->toBeInstanceOf(PostingRefused::class)
        ->and($thrown->reason)->toBe(PostingRefusal::EntryNotFound);
});

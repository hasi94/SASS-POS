<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Journal;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\JournalEntryStatus;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Finance\PostedEntry;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Finance\Journal\Fingerprint;
use Flexipos\Finance\Journal\PostingInput;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step C2 - idempotent posting (AC-34 to AC-41)
|--------------------------------------------------------------------------
|
| Everything here is sequential on the stand-in. The two-connection proof of
| AC-40 is `[ENGINE]` (NOT VERIFIED under ENGINE_HOLD); its stand-in seam is a
| spy gate that raw-inserts the competing row between the replay look-up and
| the insert, which is exactly where a real second connection would land.
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
 * @return array<string, mixed>
 */
function idemEntryValues(PostedEntry $entry): array
{
    return [
        'id' => $entry->id,
        'number' => $entry->number,
        'periodId' => $entry->periodId,
        'entryDate' => $entry->entryDate,
        'totalDebit' => $entry->totalDebit,
        'totalCredit' => $entry->totalCredit,
        'status' => $entry->status,
    ];
}

// ------------------------------------------------------------------ AC-34

it('returns the stored entry as a replay and writes, gates, checks and audits nothing (AC-34, M4)', function () {
    $world = jrnWorld();
    $body = jrnBody($world);

    $first = jrnPost($body, $world['actor']);

    $gate = jrnSpyOnGate();
    $chart = jrnSpyOnChart();
    $before = jrnState();

    [$statements, $thrown] = jrnStatements(function () use ($body, $world, &$replay): void {
        $replay = jrnPost($body, $world['actor']);
    });

    expect($thrown)->toBeNull()
        ->and($replay->replayed)->toBeTrue()
        ->and($first->replayed)->toBeFalse()
        ->and(idemEntryValues($replay))->toBe(idemEntryValues($first))
        ->and($gate->dates)->toBe([])
        ->and($chart->postable)->toBe([])
        ->and(jrnState())->toBe($before)
        ->and(jrnStatementsOn($statements, 'number_sequences'))->toBe([])
        ->and(array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/\A(insert|update|delete)\b/i', $sql) === 1)))->toBe([]);
});

// ------------------------------------------------------------------ AC-35

it('refuses the same key with any differing payload as idempotency_conflict and leaves the stored entry untouched (AC-35, M5)', function (string $difference) {
    $world = jrnWorld();
    $branch = AccessSeed::branch();
    $employee = AccessSeed::employee('dimension-employee');
    $base = jrnBody($world, [
        'branch_id' => $branch,
        'lines' => [
            ['account_id' => $world['cash'], 'debit' => '100', 'memo' => 'm'],
            ['account_id' => $world['sales'], 'credit' => '60'],
            ['account_id' => $world['other'], 'credit' => '40'],
        ],
    ]);

    $first = jrnPost($base);
    $storedRow = jrnEntry($first->id);
    $storedLines = jrnLines($first->id);
    $before = jrnState();

    $changed = match ($difference) {
        'entry date' => array_merge($base, ['entry_date' => '2026-03-16']),
        'branch' => array_merge($base, ['branch_id' => AccessSeed::branch()]),
        'branch removed' => array_merge($base, ['branch_id' => null]),
        'description' => array_merge($base, ['description' => 'Other']),
        'line count' => array_merge($base, ['lines' => array_merge($base['lines'], [['account_id' => $world['other'], 'debit' => '1'], ['account_id' => $world['other'], 'credit' => '1']])]),
        'line order' => array_merge($base, ['lines' => [$base['lines'][0], $base['lines'][2], $base['lines'][1]]]),
        'account' => array_merge($base, ['lines' => [$base['lines'][0], ['account_id' => $world['other'], 'credit' => '60'], $base['lines'][2]]]),
        'amount' => array_merge($base, ['lines' => [['account_id' => $world['cash'], 'debit' => '100', 'memo' => 'm'], ['account_id' => $world['sales'], 'credit' => '60.0001'], ['account_id' => $world['other'], 'credit' => '39.9999']]]),
        'memo' => array_merge($base, ['lines' => [['account_id' => $world['cash'], 'debit' => '100', 'memo' => 'n'], $base['lines'][1], $base['lines'][2]]]),
        'memo removed' => array_merge($base, ['lines' => [['account_id' => $world['cash'], 'debit' => '100'], $base['lines'][1], $base['lines'][2]]]),
        'dimension' => array_merge($base, ['lines' => [array_merge($base['lines'][0], ['employee_id' => $employee]), $base['lines'][1], $base['lines'][2]]]),
        default => throw new \InvalidArgumentException($difference),
    };

    $refusal = jrnPostRefusal($changed);

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe(PostingRefusal::IdempotencyConflict)
        ->and($refusal->context)->toBe(['entry_id' => $first->id])
        ->and(jrnState())->toBe($before)
        ->and(jrnEntry($first->id))->toBe($storedRow)
        ->and(jrnLines($first->id))->toBe($storedLines);
})->with([
    'entry date', 'branch', 'branch removed', 'description', 'line count', 'line order', 'account', 'amount', 'memo', 'memo removed', 'dimension',
]);

it('treats "12.5" and "12.5000", and "x" and " x ", as one payload (AC-35, D-30)', function () {
    $world = jrnWorld();
    $body = jrnBody($world, [
        'description' => 'x',
        'lines' => [['account_id' => $world['cash'], 'debit' => '12.5', 'memo' => 'x'], ['account_id' => $world['sales'], 'credit' => '12.5']],
    ]);

    $first = jrnPost($body);

    $respelled = jrnBody($world, [
        'source_id' => $body['source_id'],
        'description' => ' x ',
        'lines' => [['account_id' => strtolower($world['cash']), 'debit' => '12.5000', 'memo' => ' x '], ['account_id' => $world['sales'], 'credit' => '12.50']],
    ]);

    $again = jrnPost($respelled);

    expect($again->replayed)->toBeTrue()
        ->and($again->id)->toBe($first->id)
        ->and(jrnState()['entries'])->toBe(1);
});

// ------------------------------------------------------------------ AC-36

it('is still a replay for a different actor and a later clock (AC-36)', function () {
    $world = jrnWorld();
    $other = FinanceSeed::actor('other-actor', 'finance.accounts.view');
    $body = jrnBody($world);

    $first = jrnPost($body, $world['actor']);
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2027-01-01 00:00:00.000'));

    $again = jrnPost($body, $other);

    expect($again->replayed)->toBeTrue()
        ->and($again->id)->toBe($first->id)
        ->and(jrnEntry($first->id)['posted_by'])->toBe($world['actor'])
        ->and(jrnEntry($first->id)['posted_at'])->toBe('2026-11-10 10:00:00.000');
});

// ------------------------------------------------------------------ AC-37

it('replays after the period closed, still conflicts on a changed payload, and refuses a fresh key (AC-37)', function () {
    $world = jrnWorld();
    $body = jrnBody($world);

    $first = jrnPost($body);

    FinanceSeed::connection()->table('fiscal_periods')->where('id', $world['periods'][3])->update(['status' => 'closed']);

    $again = jrnPost($body);

    expect($again->replayed)->toBeTrue()
        ->and($again->id)->toBe($first->id);

    $conflict = jrnPostRefusal(array_merge($body, ['description' => 'changed']));

    expect($conflict->reason)->toBe(PostingRefusal::IdempotencyConflict);

    $fresh = jrnPostRefusal(array_merge($body, ['source_id' => (string) Str::ulid()]));

    expect($fresh->reason)->toBe(PeriodRefusal::PeriodClosed)
        ->and($fresh->field)->toBe('entry_date');
});

// ------------------------------------------------------------------ AC-38

it('returns the original, status reversed, when a reversed document is replayed (AC-38)', function () {
    $world = jrnWorld();
    $body = jrnBody($world);

    $first = jrnPost($body, $world['actor']);
    jrnReverse($first->id, '2026-03-20', 'customer returned', $world['actor']);

    $before = jrnState();
    $again = jrnPost($body, $world['actor']);

    expect($again->replayed)->toBeTrue()
        ->and($again->id)->toBe($first->id)
        ->and($again->status)->toBe(JournalEntryStatus::Reversed)
        ->and(jrnState())->toBe($before);
});

// ------------------------------------------------------------------ AC-39

it('keeps one row per key for a null purpose, and lets purposes coexist (AC-39, M6)', function () {
    $world = jrnWorld();
    $sourceId = (string) Str::ulid();
    $body = jrnBody($world, ['source_id' => $sourceId]);

    $first = jrnPost($body);

    expect(jrnPost($body)->replayed)->toBeTrue()
        ->and(jrnPostRefusal(array_merge($body, ['description' => 'x']))->reason)->toBe(PostingRefusal::IdempotencyConflict)
        ->and(jrnState()['entries'])->toBe(1);

    $revenue = jrnPost(array_merge($body, ['source_purpose' => 'revenue']));
    $cogs = jrnPost(array_merge($body, ['source_purpose' => 'cogs']));

    expect($revenue->replayed)->toBeFalse()
        ->and($cogs->replayed)->toBeFalse()
        ->and([$first->id, $revenue->id, $cogs->id])->toHaveCount(3)
        ->and(array_unique([$first->id, $revenue->id, $cogs->id]))->toHaveCount(3)
        ->and(jrnState()['entries'])->toBe(3);

    // And each purpose replays to its own entry.
    expect(jrnPost(array_merge($body, ['source_purpose' => 'cogs']))->id)->toBe($cogs->id)
        ->and(jrnPost($body)->id)->toBe($first->id);
});

// ------------------------------------------------------------------ AC-40 stand-in seam

it('answers a lost same-key race with the winner as a replay, no QueryException, and the loser consumes no number (AC-40, M15)', function () {
    $world = jrnWorld();
    $body = jrnBody($world);
    $request = PostingRequest::fromArray($body, null);
    $fingerprint = Fingerprint::ofPosting(PostingInput::parse($request));

    $winner = null;

    jrnSpyOnGate(function () use ($world, $body, $fingerprint, &$winner): void {
        $winner ??= jrnRawEntry($world['periods'][3], [
            'number' => 'JE-WINNER',
            'source_id' => strtoupper($body['source_id']),
            'payload_fingerprint' => $fingerprint,
        ]);
    });

    $before = jrnState();

    $entry = jrnPost($body);

    expect($entry->replayed)->toBeTrue()
        ->and($entry->id)->toBe($winner['id'])
        ->and($entry->number)->toBe('JE-WINNER')
        ->and(FinanceSeed::connection()->table('journal_entries')->count())->toBe(1)
        ->and(jrnState()['next'])->toBe($before['next'])
        ->and(jrnState()['audit'])->toBe($before['audit'])
        ->and(jrnState()['lines'])->toBe(0);
});

it('answers a lost same-key race with a different payload as idempotency_conflict, never a QueryException (AC-40)', function () {
    $world = jrnWorld();
    $body = jrnBody($world);

    jrnSpyOnGate(function () use ($world, $body): void {
        static $done = false;

        if (! $done) {
            $done = true;
            jrnRawEntry($world['periods'][3], ['source_id' => strtoupper($body['source_id']), 'payload_fingerprint' => str_repeat('a', 64)]);
        }
    });

    $before = jrnState();

    // Read inside the caller's transaction: a refusal rolls the whole of it back, the raw winner included.
    [$refusal, $inside] = jrnInTransaction(function () use ($body): array {
        try {
            app(PostingService::class)->post(PostingRequest::fromArray($body, null));
        } catch (PostingRefused $refused) {
            return [$refused, jrnState()];
        }

        return [null, jrnState()];
    });

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe(PostingRefusal::IdempotencyConflict)
        ->and($inside['next'])->toBe($before['next'])
        ->and($inside['entries'])->toBe(1)
        ->and($inside['lines'])->toBe(0);
});

// ------------------------------------------------------------------ AC-41

it('creates two entries for a manual or opening post with no source id, posted twice (AC-41)', function (string $type) {
    $world = jrnWorld();
    $body = jrnBody($world, ['source_type' => $type, 'source_id' => null]);

    $first = jrnPost($body);
    $second = jrnPost($body);

    expect($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeFalse()
        ->and($second->id)->not->toBe($first->id)
        ->and([$first->number, $second->number])->toBe(['JE-000001', 'JE-000002'])
        ->and(jrnState()['entries'])->toBe(2);
})->with(['manual', 'opening']);

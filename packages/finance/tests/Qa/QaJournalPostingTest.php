<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Qa;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\JournalEntryStatus;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Finance\PostedEntry;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Finance\Journal\JournalWriter;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 QA - exploratory probes on the posting service
|--------------------------------------------------------------------------
|
| Cases the slice tests do not pin: numbering through a mixed run of commits,
| refusals, replays and a rolled-back caller; the recreated sequence row
| rolling back with its entry (AC-33b); the mirror property of a reversal
| across a repeating account; the exact money boundary; reversal under a
| soft-closed period for a system actor; case folding of ids on both entry
| points. SQLite stand-in only; engine-only clauses stay NOT VERIFIED.
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

it('numbers committed entries 1 to N with no gap through refusals, replays and a rolled-back caller (AC-33)', function () {
    $world = jrnWorld();
    $first = jrnBody($world);

    $a = jrnPost($first, $world['actor']);
    $refused = jrnPostRefusal(jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => '10.00'],
        ['account_id' => $world['sales'], 'credit' => '9.9999'],
    ]]), $world['actor']);
    $replay = jrnPost($first, $world['actor']);

    try {
        jrnInTransaction(function () use ($world): void {
            qaPostInside(jrnBody($world), $world['actor']);

            throw new RuntimeException('the caller fails after posting');
        });
    } catch (RuntimeException) {
        // The caller's own failure; the point is what it leaves behind.
    }

    $b = jrnPost(jrnBody($world), $world['actor']);

    expect($refused)->toBeInstanceOf(PostingRefused::class)
        ->and($refused->reason)->toBe(PostingRefusal::EntryUnbalanced)
        ->and($a->number)->toBe('JE-000001')
        ->and($replay->replayed)->toBeTrue()
        ->and($replay->number)->toBe('JE-000001')
        ->and($b->number)->toBe('JE-000002')
        ->and(jrnState()['entries'])->toBe(2)
        ->and(jrnState()['next'])->toBe(3);
});

it('rolls a recreated sequence row back with its entry, and recreates it with the seeded format again (AC-33b)', function () {
    $world = jrnWorld();
    $connection = FinanceSeed::connection();
    $connection->table('number_sequences')->where('document_type', 'journal_entry')->delete();

    try {
        jrnInTransaction(function () use ($world): void {
            qaPostInside(jrnBody($world), $world['actor']);

            throw new RuntimeException('the caller fails after posting');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect($connection->table('number_sequences')->where('document_type', 'journal_entry')->count())->toBe(0)
        ->and($connection->table('journal_entries')->count())->toBe(0);

    $entry = jrnPost(jrnBody($world), $world['actor']);
    $row = (array) $connection->table('number_sequences')->where('document_type', 'journal_entry')->first();

    expect($entry->number)->toBe('JE-000001')
        ->and($row['prefix'])->toBe('JE-')
        ->and((int) $row['padding'])->toBe(6)
        ->and((int) $row['next_number'])->toBe(2);
});

it('writes a reversal whose per-account net against the original is exactly zero, across a repeating account and dimensions (AC-49, AC-50)', function () {
    $world = jrnWorld();
    $location = jrnForeignRow('locations');
    $customer = jrnForeignRow('customers');

    $original = jrnPost(jrnBody($world, [
        'lines' => [
            ['account_id' => $world['cash'], 'debit' => '33.3333', 'memo' => 'a', 'location_id' => $location],
            ['account_id' => $world['cash'], 'debit' => '66.6667', 'customer_id' => $customer],
            ['account_id' => $world['sales'], 'credit' => '0.0001'],
            ['account_id' => $world['other'], 'credit' => '99.9999'],
            ['account_id' => $world['sales'], 'debit' => '0.0001'],
            ['account_id' => $world['sales'], 'credit' => '0.0001'],
        ],
    ]), $world['actor']);

    $reversal = jrnReverse($original->id, '2026-03-16', 'wrong customer', $world['actor']);

    $net = [];
    $mirror = true;
    $originalLines = jrnLines($original->id);
    $reversalLines = jrnLines($reversal->id);

    foreach ([$originalLines, $reversalLines] as $set) {
        foreach ($set as $line) {
            $net[$line['account_id']] = bcadd($net[$line['account_id']] ?? '0', bcsub((string) $line['debit'], (string) $line['credit'], 4), 4);
        }
    }

    foreach ($originalLines as $index => $line) {
        $other = $reversalLines[$index];
        $mirror = $mirror
            && bccomp((string) $line['debit'], (string) $other['credit'], 4) === 0
            && bccomp((string) $line['credit'], (string) $other['debit'], 4) === 0
            && $line['location_id'] === $other['location_id']
            && $line['customer_id'] === $other['customer_id']
            && $line['memo'] === $other['memo']
            && (int) $line['line_no'] === (int) $other['line_no'];
    }

    expect(count($reversalLines))->toBe(6)
        ->and($mirror)->toBeTrue()
        ->and(array_filter($net, static fn (string $value): bool => bccomp($value, '0', 4) !== 0))->toBe([])
        ->and($reversal->status)->toBe(JournalEntryStatus::Posted)
        ->and(jrnEntry($original->id)['status'])->toBe('reversed')
        ->and(jrnEntry($original->id)['reversed_by_id'])->toBe($reversal->id)
        ->and(jrnEntry($reversal->id)['reversal_of_id'])->toBe($original->id);
});

it('accepts the largest legal total and refuses one step past it, to the last decimal place (AC-18, AC-21, AC-22)', function () {
    $world = jrnWorld();
    $largest = '99999999999999.9999';

    $entry = jrnPost(jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => $largest],
        ['account_id' => $world['sales'], 'credit' => $largest],
    ]]), $world['actor']);

    $tooWide = jrnPostRefusal(jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => '100000000000000'],
        ['account_id' => $world['sales'], 'credit' => '100000000000000'],
    ]]), $world['actor']);

    $overSum = jrnPostRefusal(jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => $largest],
        ['account_id' => $world['cash'], 'debit' => '0.0001'],
        ['account_id' => $world['sales'], 'credit' => $largest],
        ['account_id' => $world['sales'], 'credit' => '0.0001'],
    ]]), $world['actor']);

    $off = jrnPostRefusal(jrnBody($world, ['lines' => [
        ['account_id' => $world['cash'], 'debit' => '0.0001'],
        ['account_id' => $world['sales'], 'credit' => '0.0002'],
    ]]), $world['actor']);

    expect($entry->totalDebit)->toBe($largest)
        ->and($entry->totalCredit)->toBe($largest)
        ->and($tooWide?->reason)->toBe(PostingRefusal::InvalidAmount)
        ->and($overSum?->reason)->toBe(PostingRefusal::TotalOutOfRange)
        ->and($off?->reason)->toBe(PostingRefusal::EntryUnbalanced)
        ->and(jrnState()['entries'])->toBe(1);
});

it('refuses a reversal for a system actor in a soft-closed period and leaves the original posted (AC-54, AC-74)', function () {
    $world = jrnWorld();
    $original = jrnPost(jrnBody($world), $world['actor']);

    FinanceSeed::connection()->table('fiscal_periods')->where('id', $world['periods'][4])->update(['status' => 'soft_closed']);

    $before = jrnState();
    $refusal = jrnReverseRefusal($original->id, '2026-04-02', 'late fix', null);

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe(PeriodRefusal::PeriodSoftClosed)
        ->and(jrnState())->toBe($before)
        ->and(jrnEntry($original->id)['status'])->toBe('posted')
        ->and(jrnEntry($original->id)['reversed_by_id'])->toBeNull();
});

it('folds a lower-case source id and entry id to upper case on post, replay and reverse (AC-7, AC-52)', function () {
    $world = jrnWorld();
    $sourceId = strtoupper((string) Str::ulid());

    $first = jrnPost(jrnBody($world, ['source_id' => strtolower($sourceId)]), $world['actor']);
    $replay = jrnPost(jrnBody($world, ['source_id' => $sourceId]), $world['actor']);
    $reversal = jrnReverse(strtolower($first->id), '2026-03-15', 'folded id', $world['actor']);

    expect($replay->replayed)->toBeTrue()
        ->and($replay->id)->toBe($first->id)
        ->and(jrnEntry($first->id)['source_id'])->toBe($sourceId)
        ->and(jrnEntry($reversal->id)['reversal_of_id'])->toBe($first->id)
        ->and(jrnState()['entries'])->toBe(2);
});

it('refuses to reverse a reversal and leaves the first reversal untouched (AC-52)', function () {
    $world = jrnWorld();
    $original = jrnPost(jrnBody($world), $world['actor']);
    $reversal = jrnReverse($original->id, '2026-03-16', 'first', $world['actor']);

    $before = jrnState();
    $refusal = jrnReverseRefusal($reversal->id, '2026-03-17', 'undo the undo', $world['actor']);
    $again = jrnReverseRefusal($original->id, '2026-03-18', 'second try', $world['actor']);

    expect($refusal?->reason)->toBe(PostingRefusal::NotReversible)
        ->and($again?->reason)->toBe(PostingRefusal::AlreadyReversed)
        ->and(jrnState())->toBe($before)
        ->and(jrnEntry($reversal->id)['status'])->toBe('posted');
});

/**
 * `post` inside the caller's transaction, which the caller opens and may roll back.
 *
 * @param  array<string, mixed>  $body
 */
function qaPostInside(array $body, ?string $actor): PostedEntry
{
    return app(PostingService::class)->post(PostingRequest::fromArray($body, $actor));
}

it('flips an entry to reversed once only: a second flip of the same row changes nothing and keeps the first reversal (AC-48, AC-57)', function () {
    $world = jrnWorld();
    $original = jrnPost(jrnBody($world), $world['actor']);
    $reversal = jrnReverse($original->id, '2026-03-16', 'first', $world['actor']);
    $writer = app(JournalWriter::class);

    $changed = $writer->markReversed($original->id, 'SECONDREVERSAL0000000000', '2026-11-10 10:00:01.000');

    expect($changed)->toBe(0)
        ->and(jrnEntry($original->id)['reversed_by_id'])->toBe($reversal->id)
        ->and(jrnEntry($original->id)['status'])->toBe('reversed');
});

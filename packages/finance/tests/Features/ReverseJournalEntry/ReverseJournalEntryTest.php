<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\ReverseJournalEntry;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\PostedEntry;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Finance\Features\ReverseJournalEntry\ReverseJournalEntry;
use Flexipos\Finance\Features\ReverseJournalEntry\ReverseJournalEntryHandler;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step D2 - ReverseJournalEntry (AC-70, AC-73)
|--------------------------------------------------------------------------
|
| The handler owns the transaction, so every call here is made outside one. A
| module reversing its own document calls the service directly and needs no key
| (AC-58, covered with the service); this is the human path.
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

function reverseEntryAs(?string $actor, mixed $id, mixed $date = '2026-03-20', mixed $reason = 'mistake'): PostedEntry
{
    return app(ReverseJournalEntryHandler::class)->handle(new ReverseJournalEntry($id, $date, $reason, $actor));
}

function reverseReverser(): string
{
    return FinanceSeed::actor('reverser', FinancePermissions::JOURNAL_REVERSE);
}

it('reverses an entry as the employee, in its own transaction, with one audit row (AC-70)', function () {
    $world = jrnWorld();
    $reverser = reverseReverser();
    $original = jrnPost(jrnBody($world), $world['actor']);
    $connection = FinanceSeed::connection();
    $before = jrnState();

    expect($connection->transactionLevel())->toBe(0);

    $reversal = reverseEntryAs($reverser, $original->id);

    expect($connection->transactionLevel())->toBe(0)
        ->and($reversal->replayed)->toBeFalse()
        ->and($reversal->number)->toBe('JE-000002')
        ->and(jrnEntry($original->id)['status'])->toBe('reversed')
        ->and(jrnEntry($original->id)['reversed_by_id'])->toBe($reversal->id)
        ->and(jrnEntry($reversal->id))->toMatchArray([
            'source_type' => 'reversal', 'source_id' => $original->id, 'reversal_of_id' => $original->id,
            'posted_by' => $reverser, 'created_by' => $reverser, 'description' => 'mistake',
        ])
        ->and(jrnState())->toBe(['entries' => $before['entries'] + 1, 'lines' => $before['lines'] + 2, 'audit' => $before['audit'] + 1, 'next' => 3]);

    $audit = array_values(array_filter(FinanceSeed::audit(), static fn (array $row): bool => $row['action'] === 'journal_entry_reversed'));

    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor_id'])->toBe($reverser)
        ->and($audit[0]['subject_id'])->toBe($reversal->id);
});

it('replays an identical reversal and refuses a different one, with nothing written (AC-70)', function () {
    $world = jrnWorld();
    $reverser = reverseReverser();
    $original = jrnPost(jrnBody($world), $world['actor']);

    $first = reverseEntryAs($reverser, $original->id);
    $before = jrnState();

    expect(reverseEntryAs($reverser, $original->id)->replayed)->toBeTrue()
        ->and(reverseEntryAs($reverser, $original->id)->id)->toBe($first->id);

    $refusal = null;

    try {
        reverseEntryAs($reverser, $original->id, '2026-03-21');
    } catch (PostingRefused $refusal) {
        // expected
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->reason)->toBe(PostingRefusal::AlreadyReversed)
        ->and(jrnState())->toBe($before);
});

it('passes the service refusals through unchanged, rolled back, and leaves the connection out of a transaction (AC-70)', function (mixed $id, mixed $date, mixed $reason, PostingRefusal $expected) {
    $world = jrnWorld();
    $reverser = reverseReverser();
    $original = jrnPost(jrnBody($world), $world['actor']);
    $before = jrnState();

    $refusal = null;

    try {
        reverseEntryAs($reverser, $id === 'ORIGINAL' ? $original->id : $id, $date, $reason);
    } catch (PostingRefused $refusal) {
        // expected
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->reason)->toBe($expected)
        ->and(jrnState())->toBe($before)
        ->and(jrnEntry($original->id)['status'])->toBe('posted')
        ->and(FinanceSeed::connection()->transactionLevel())->toBe(0);
})->with(function () {
    return [
        'an unknown entry' => [(string) Str::ulid(), '2026-03-20', 'x', PostingRefusal::EntryNotFound],
        'a malformed id' => ['not-an-id', '2026-03-20', 'x', PostingRefusal::EntryNotFound],
        'a missing date' => ['ORIGINAL', null, 'x', PostingRefusal::InvalidDate],
        'a blank reason' => ['ORIGINAL', '2026-03-20', '  ', PostingRefusal::ReasonRequired],
        'a date before the original' => ['ORIGINAL', '2026-03-14', 'x', PostingRefusal::ReversalBeforeOriginal],
    ];
});

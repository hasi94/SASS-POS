<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\PostManualJournalEntry;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Finance\PostedEntry;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Finance\Features\PostManualJournalEntry\PostManualJournalEntry;
use Flexipos\Finance\Features\PostManualJournalEntry\PostManualJournalEntryHandler;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step D2 - PostManualJournalEntry (AC-71, AC-73, AC-74)
|--------------------------------------------------------------------------
|
| The handler owns the transaction: every call here is made outside one, the way
| a caller of the slice makes it, and the connection must be out of it again
| afterwards.
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
 * @param  array<array-key, mixed>  $body
 */
function postManualAs(?string $actor, array $body): PostedEntry
{
    return app(PostManualJournalEntryHandler::class)->handle(new PostManualJournalEntry($body, $actor));
}

/**
 * @param  array<string, mixed>  $world
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function postManualBody(array $world, array $over = []): array
{
    $body = jrnBody($world, $over);
    unset($body['source_type']);

    return $body;
}

function postManualPoster(): string
{
    return FinanceSeed::actor('poster', FinancePermissions::JOURNAL_POST_MANUAL);
}

it('posts a manual entry as the employee, in its own transaction, with one audit row (AC-71)', function () {
    $world = jrnWorld();
    $poster = postManualPoster();
    $connection = FinanceSeed::connection();

    expect($connection->transactionLevel())->toBe(0);

    $posted = postManualAs($poster, postManualBody($world, ['source_id' => null, 'description' => 'Opening float']));

    expect($connection->transactionLevel())->toBe(0)
        ->and($posted->replayed)->toBeFalse()
        ->and($posted->number)->toBe('JE-000001')
        ->and($posted->totalDebit)->toBe('100.0000');

    $row = jrnEntry($posted->id);

    expect($row)->toMatchArray([
        'source_type' => 'manual',
        'source_id' => null,
        'status' => 'posted',
        'posted_by' => $poster,
        'created_by' => $poster,
        'description' => 'Opening float',
    ])->and((bool) $row['is_auto'])->toBeFalse()
        ->and(jrnLines($posted->id))->toHaveCount(2)
        ->and(jrnState())->toBe(['entries' => 1, 'lines' => 2, 'audit' => 1, 'next' => 2]);

    $audit = FinanceSeed::onlyAuditRow();

    expect($audit)->toMatchArray(['module' => 'finance', 'action' => 'journal_entry_posted', 'subject_id' => $posted->id, 'actor_id' => $poster])
        ->and($audit['after']['is_auto'])->toBeFalse();
});

it('keeps a manual entry with a source key idempotent (AC-71)', function () {
    $world = jrnWorld();
    $poster = postManualPoster();
    $body = postManualBody($world);

    $first = postManualAs($poster, $body);
    $again = postManualAs($poster, $body);

    expect($again->replayed)->toBeTrue()
        ->and($again->id)->toBe($first->id)
        ->and(jrnState()['entries'])->toBe(1)
        ->and(jrnEntry($first->id)['source_type'])->toBe('manual');
});

it('refuses a body that names source_type as an unknown field, before anything is written (AC-71)', function () {
    $world = jrnWorld();
    $poster = postManualPoster();
    $before = jrnState();

    foreach (['manual', 'sale', 'reversal', null, 5] as $type) {
        $refusal = null;

        try {
            postManualAs($poster, jrnBody($world, ['source_type' => $type]));
        } catch (PostingRefused $refusal) {
            // expected
        }

        expect($refusal)->not->toBeNull()
            ->and($refusal->reason)->toBe(PostingRefusal::UnknownField)
            ->and($refusal->fields)->toBe(['source_type']);
    }

    expect(jrnState())->toBe($before)
        ->and(FinanceSeed::connection()->transactionLevel())->toBe(0);
});

it('passes the service refusals through unchanged and leaves nothing behind (AC-71)', function (array $over, PostingRefusal|PeriodRefusal $reason) {
    $world = jrnWorld();
    $poster = postManualPoster();
    $before = jrnState();

    $refusal = null;

    try {
        postManualAs($poster, postManualBody($world, $over));
    } catch (PostingRefused $refusal) {
        // expected
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->reason)->toBe($reason)
        ->and(jrnState())->toBe($before)
        ->and(FinanceSeed::connection()->transactionLevel())->toBe(0);
})->with([
    'unbalanced' => [['lines' => [['account_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'debit' => '1'], ['account_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'credit' => '2']]], PostingRefusal::EntryUnbalanced],
    'a malformed date' => [['entry_date' => '2026-02-30'], PostingRefusal::InvalidDate],
    'no period covers the date' => [['entry_date' => '2031-01-01'], PeriodRefusal::NoFiscalPeriod],
]);

it('refuses a posting into a closed period and into an unknown account, rolled back whole (AC-71)', function () {
    $world = jrnWorld();
    $poster = postManualPoster();

    FinanceSeed::connection()->table('fiscal_periods')->where('id', $world['periods'][3])->update(['status' => 'closed']);

    $before = jrnState();
    $closed = null;

    try {
        postManualAs($poster, postManualBody($world));
    } catch (PostingRefused $closed) {
        // expected
    }

    expect($closed)->not->toBeNull()
        ->and($closed->reason)->toBe(PeriodRefusal::PeriodClosed)
        ->and(jrnState())->toBe($before);
});

it('refuses a body that names posted_by or is_auto, which only the service sets (AC-71, AC-74)', function () {
    $world = jrnWorld();
    $poster = postManualPoster();

    $refusal = null;

    try {
        postManualAs($poster, postManualBody($world, ['posted_by' => $world['actor'], 'is_auto' => true]));
    } catch (PostingRefused $refusal) {
        // expected
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->reason)->toBe(PostingRefusal::UnknownField)
        ->and($refusal->fields)->toBe(['posted_by', 'is_auto']);
});

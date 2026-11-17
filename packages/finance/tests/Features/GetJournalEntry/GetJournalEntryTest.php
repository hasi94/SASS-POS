<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Features\GetJournalEntry;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Features\GetJournalEntry\GetJournalEntry;
use Flexipos\Finance\Features\GetJournalEntry\GetJournalEntryHandler;
use Flexipos\Finance\Features\GetJournalEntry\GetJournalEntryResult;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Tests\Support\FinanceRefusals;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step D2 - GetJournalEntry (AC-68, D-37)
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

function getEntryAs(?string $actor, mixed $id = null, mixed $type = null, mixed $sourceId = null, mixed $purpose = null): GetJournalEntryResult
{
    return app(GetJournalEntryHandler::class)->handle(new GetJournalEntry($id, $type, $sourceId, $purpose, $actor));
}

it('returns the entry whole with its lines by line_no and every amount as a four-decimal string (AC-68)', function () {
    $world = jrnWorld();
    $viewer = FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);
    $source = (string) Str::ulid();

    $posted = jrnPost(jrnBody($world, [
        'source_id' => $source,
        'source_purpose' => 'revenue',
        'description' => '  Takings  ',
        'lines' => [
            ['account_id' => $world['cash'], 'debit' => '100.5', 'memo' => 'cash in'],
            ['account_id' => $world['sales'], 'credit' => '60'],
            ['account_id' => $world['other'], 'credit' => '40.5000', 'memo' => 'other'],
        ],
    ]), $world['actor']);

    $result = getEntryAs($viewer, $posted->id);

    expect($result->entry)->toBe([
        'id' => $posted->id,
        'number' => 'JE-000001',
        'entry_date' => '2026-03-15',
        'period_id' => $world['periods'][3],
        'branch_id' => null,
        'source_type' => 'sale',
        'source_id' => strtoupper($source),
        'source_purpose' => 'revenue',
        'status' => 'posted',
        'total_debit' => '100.5000',
        'total_credit' => '100.5000',
        'description' => 'Takings',
        'reversal_of_id' => null,
        'reversed_by_id' => null,
        'posted_at' => '2026-11-10 10:00:00.000',
        'posted_by' => $world['actor'],
        'is_auto' => false,
        'created_by' => $world['actor'],
        'created_at' => '2026-11-10 10:00:00.000',
        'updated_at' => '2026-11-10 10:00:00.000',
    ])->and($result->lines)->toHaveCount(3);

    expect(array_column($result->lines, 'line_no'))->toBe([1, 2, 3])
        ->and(array_column($result->lines, 'account_id'))->toBe([$world['cash'], $world['sales'], $world['other']])
        ->and(array_column($result->lines, 'debit'))->toBe(['100.5000', '0.0000', '0.0000'])
        ->and(array_column($result->lines, 'credit'))->toBe(['0.0000', '60.0000', '40.5000'])
        ->and(array_column($result->lines, 'memo'))->toBe(['cash in', null, 'other'])
        ->and(array_keys($result->lines[0]))->toBe([
            'id', 'line_no', 'account_id', 'debit', 'credit', 'memo',
            'branch_id', 'location_id', 'department_id', 'employee_id', 'customer_id', 'supplier_id', 'product_id', 'created_at',
        ]);
});

it('reads an entry by its source key, a null purpose through the key column, and tells purposes apart (AC-68)', function () {
    $world = jrnWorld();
    $viewer = FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);
    $source = (string) Str::ulid();

    $none = jrnPost(jrnBody($world, ['source_id' => $source]));
    $revenue = jrnPost(jrnBody($world, ['source_id' => $source, 'source_purpose' => 'revenue']));
    $cogs = jrnPost(jrnBody($world, ['source_id' => $source, 'source_purpose' => 'cogs']));

    expect(getEntryAs($viewer, null, 'sale', $source)->entry['id'])->toBe($none->id)
        ->and(getEntryAs($viewer, null, 'sale', strtolower($source))->entry['id'])->toBe($none->id)
        ->and(getEntryAs($viewer, null, 'sale', $source, 'revenue')->entry['id'])->toBe($revenue->id)
        ->and(getEntryAs($viewer, null, 'sale', $source, 'cogs')->entry['id'])->toBe($cogs->id)
        ->and(getEntryAs($viewer, null, 'sale', $source)->entry['source_purpose'])->toBeNull();

    $missing = FinanceRefusals::expect(FinanceRefusal::EntryNotFound, fn () => getEntryAs($viewer, null, 'sale', $source, 'refund'));

    expect($missing->reason->value)->toBe(PostingRefusal::EntryNotFound->value);

    FinanceRefusals::expect(FinanceRefusal::EntryNotFound, fn () => getEntryAs($viewer, null, 'sale_refund', $source));
    FinanceRefusals::expect(FinanceRefusal::EntryNotFound, fn () => getEntryAs($viewer, null, 'sale', (string) Str::ulid()));
});

it('reads a reversal by the key of the entry it reverses, and shows the flipped original (AC-68)', function () {
    $world = jrnWorld();
    $viewer = FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);

    $original = jrnPost(jrnBody($world), $world['actor']);
    $reversal = jrnReverse($original->id, '2026-03-20', 'mistake', $world['actor']);

    $byKey = getEntryAs($viewer, null, 'reversal', $original->id);
    $flipped = getEntryAs($viewer, $original->id);

    expect($byKey->entry['id'])->toBe($reversal->id)
        ->and($byKey->entry['reversal_of_id'])->toBe($original->id)
        ->and($byKey->entry['description'])->toBe('mistake')
        ->and($flipped->entry['status'])->toBe('reversed')
        ->and($flipped->entry['reversed_by_id'])->toBe($reversal->id);
});

it('reads a system posting as automatic with no poster (AC-68, AC-74)', function () {
    $world = jrnWorld();
    $viewer = FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);

    $posted = jrnPost(jrnBody($world));
    $entry = getEntryAs($viewer, $posted->id)->entry;

    expect($entry['is_auto'])->toBeTrue()
        ->and($entry['posted_by'])->toBeNull()
        ->and($entry['created_by'])->toBeNull();
});

it('refuses an unknown id entry_not_found, with the same string the posting service uses (AC-68, D-37)', function () {
    $viewer = FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);

    $refused = FinanceRefusals::expect(FinanceRefusal::EntryNotFound, fn () => getEntryAs($viewer, (string) Str::ulid()));

    expect($refused->reason->value)->toBe(PostingRefusal::EntryNotFound->value)
        ->and($refused->field)->toBe('entry_id');
});

it('refuses malformed input as validation, case-sensitively, before any read of the journal', function (array $arguments) {
    $world = jrnWorld();
    $viewer = FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);
    jrnPost(jrnBody($world));

    [$statements, $thrown] = jrnStatements(fn () => getEntryAs($viewer, ...$arguments));

    if (! $thrown instanceof FinanceRefused) {
        Assert::fail('A FinanceRefused was expected.');
    }

    expect($thrown->reason)->toBe(FinanceRefusal::Validation)
        ->and(jrnStatementsOn($statements, 'journal_entries'))->toBe([])
        ->and(jrnStatementsOn($statements, 'journal_lines'))->toBe([]);
})->with(function () {
    $id = (string) Str::ulid();

    return [
        'nothing named' => [[]],
        'a malformed id' => [['id' => 'not-an-id']],
        'an id as an integer' => [['id' => 5]],
        'an id and a source key' => [['id' => $id, 'type' => 'sale', 'sourceId' => $id]],
        'a type of the wrong case' => [['type' => 'Sale', 'sourceId' => $id]],
        'an unknown type' => [['type' => 'invoice', 'sourceId' => $id]],
        'a type without a source id' => [['type' => 'sale']],
        'a malformed source id' => [['type' => 'sale', 'sourceId' => 'x']],
        'a purpose of the wrong case' => [['type' => 'sale', 'sourceId' => $id, 'purpose' => 'Revenue']],
        'the stored no-purpose sentinel' => [['type' => 'sale', 'sourceId' => $id, 'purpose' => '-']],
        'an empty purpose' => [['type' => 'sale', 'sourceId' => $id, 'purpose' => '']],
        'a purpose with a trailing newline' => [['type' => 'sale', 'sourceId' => $id, 'purpose' => "rev\n"]],
    ];
});

it('does not let the stored sentinel alias the null-purpose entry (AC-68)', function () {
    $world = jrnWorld();
    $viewer = FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);
    $source = (string) Str::ulid();

    jrnPost(jrnBody($world, ['source_id' => $source]));

    expect(getEntryAs($viewer, null, 'sale', $source)->entry['source_purpose'])->toBeNull();

    FinanceRefusals::expect(FinanceRefusal::Validation, fn () => getEntryAs($viewer, null, 'sale', $source, '-'));
});

it('writes nothing and records no audit row (AC-66)', function () {
    $world = jrnWorld();
    $viewer = FinanceSeed::actor('viewer', FinancePermissions::JOURNAL_VIEW);
    $posted = jrnPost(jrnBody($world));
    $before = jrnState();

    getEntryAs($viewer, $posted->id);

    expect(jrnState())->toBe($before);
});

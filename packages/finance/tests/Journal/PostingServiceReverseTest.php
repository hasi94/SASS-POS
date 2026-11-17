<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Journal;

use Closure;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Finance\AccountRefusal;
use Flexipos\Contracts\Finance\JournalEntryStatus;
use Flexipos\Contracts\Finance\PeriodRefusal;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Contracts\Finance\ReversalRequest;
use Flexipos\Finance\Journal\Amounts;
use Flexipos\Finance\Journal\Fingerprint;
use Flexipos\Finance\Journal\JournalRows;
use Flexipos\Finance\Journal\JournalWriter;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-033 plan step C5 - `reverse` (AC-49 to AC-58, AC-67)
|--------------------------------------------------------------------------
|
| The two-connection proof of AC-57 is `[ENGINE]` (NOT VERIFIED under
| ENGINE_HOLD); its stand-in seam is a `JournalRows` double that flips the
| original between the plain read and `entryForUpdate`.
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

const REV_DIMENSIONS = ['branch_id', 'location_id', 'department_id', 'employee_id', 'customer_id', 'supplier_id', 'product_id'];

/**
 * A posted original with a branch on the entry and every checked dimension on its first line.
 *
 * @param  array<string, mixed>  $world
 * @return array{id: string, branch: string, dims: array<string, string>, body: array<string, mixed>}
 */
function revOriginal(array $world, ?string $actor = null): array
{
    $branch = AccessSeed::branch();
    $dims = [
        'branch_id' => $branch,
        'location_id' => jrnForeignRow('locations'),
        'employee_id' => AccessSeed::employee('rev-'.strtolower(Str::random(8))),
        'customer_id' => jrnForeignRow('customers'),
        'product_id' => jrnForeignRow('products'),
    ];

    $body = jrnBody($world, [
        'branch_id' => $branch,
        'lines' => [
            array_merge(['account_id' => $world['cash'], 'debit' => '100.5', 'memo' => 'cash in'], $dims),
            ['account_id' => $world['sales'], 'credit' => '60'],
            ['account_id' => $world['other'], 'credit' => '40.5', 'memo' => 'other'],
        ],
    ]);

    return ['id' => jrnPost($body, $actor)->id, 'branch' => $branch, 'dims' => $dims, 'body' => $body];
}

// ------------------------------------------------------------------ AC-49, AC-50

it('writes the mirror entry: type, links, number, swapped sides, the same dimensions and line numbers, equal totals (AC-49)', function () {
    $world = jrnWorld();
    $original = revOriginal($world, $world['actor']);
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-12 08:00:00.000'));

    $reversal = jrnReverse($original['id'], '2026-03-20', '  wrong customer  ', $world['actor']);

    expect($reversal->replayed)->toBeFalse()
        ->and($reversal->number)->toBe('JE-000002')
        ->and($reversal->status)->toBe(JournalEntryStatus::Posted)
        ->and($reversal->entryDate)->toBe('2026-03-20')
        ->and($reversal->periodId)->toBe($world['periods'][3])
        ->and($reversal->totalDebit)->toBe('100.5000')
        ->and($reversal->totalCredit)->toBe('100.5000');

    $row = jrnEntry($reversal->id);
    unset($row['payload_fingerprint']);
    $row['total_debit'] = Amounts::fromStored($row['total_debit']);
    $row['total_credit'] = Amounts::fromStored($row['total_credit']);
    $row['is_auto'] = (bool) $row['is_auto'];

    expect($row)->toBe([
        'id' => $reversal->id,
        'number' => 'JE-000002',
        'entry_date' => '2026-03-20',
        'period_id' => $world['periods'][3],
        'branch_id' => $original['branch'],
        'source_type' => 'reversal',
        'source_id' => $original['id'],
        'source_purpose' => null,
        'source_purpose_key' => '-',
        'status' => 'posted',
        'total_debit' => '100.5000',
        'total_credit' => '100.5000',
        'description' => 'wrong customer',
        'reversal_of_id' => $original['id'],
        'reversed_by_id' => null,
        'posted_at' => '2026-11-12 08:00:00.000',
        'posted_by' => $world['actor'],
        'is_auto' => false,
        'created_by' => $world['actor'],
        'created_at' => '2026-11-12 08:00:00.000',
        'updated_at' => '2026-11-12 08:00:00.000',
    ]);

    $strip = static function (array $line): array {
        unset($line['id'], $line['entry_id'], $line['created_at']);
        $line['debit'] = Amounts::fromStored($line['debit']);
        $line['credit'] = Amounts::fromStored($line['credit']);

        return $line;
    };

    $originalLines = array_map($strip, jrnLines($original['id']));
    $reversalLines = array_map($strip, jrnLines($reversal->id));

    expect($reversalLines)->toHaveCount(3);

    foreach ($originalLines as $index => $line) {
        $mirror = $reversalLines[$index];

        expect($mirror['debit'])->toBe($line['credit'], 'M10: sides are swapped')
            ->and($mirror['credit'])->toBe($line['debit'])
            ->and($mirror['line_no'])->toBe($line['line_no'])
            ->and($mirror['account_id'])->toBe($line['account_id'])
            ->and($mirror['memo'])->toBe($line['memo']);

        foreach (REV_DIMENSIONS as $dimension) {
            expect($mirror[$dimension])->toBe($line[$dimension], $dimension);
        }
    }

    expect($reversalLines[0]['location_id'])->toBe($original['dims']['location_id']);
});

it('flips only status, reversed_by_id and updated_at on the original, in the one transaction (AC-50)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);
    $before = jrnEntry($original['id']);
    $linesBefore = jrnLines($original['id']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-12 08:00:00.000'));
    $reversal = jrnReverse($original['id'], '2026-03-20', 'mistake');

    $after = jrnEntry($original['id']);

    expect($after['status'])->toBe('reversed')
        ->and($after['reversed_by_id'])->toBe($reversal->id)
        ->and($after['updated_at'])->toBe('2026-11-12 08:00:00.000');

    unset($before['status'], $before['reversed_by_id'], $before['updated_at'], $after['status'], $after['reversed_by_id'], $after['updated_at']);

    expect($after)->toBe($before)
        ->and(jrnLines($original['id']))->toBe($linesBefore);

    // Roll the whole transaction back and nothing of either half survives.
    $second = revOriginal($world);

    try {
        jrnInTransaction(function () use ($second): void {
            app(PostingService::class)->reverse(ReversalRequest::fromArray(['entry_id' => $second['id'], 'entry_date' => '2026-03-21', 'reason' => 'x'], null));

            throw new RuntimeException('caller failed after reverse');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(jrnEntry($second['id'])['status'])->toBe('posted')
        ->and(jrnEntry($second['id'])['reversed_by_id'])->toBeNull();
});

// ------------------------------------------------------------------ AC-51

it('replays an identical second reversal, and refuses a different date or reason as already_reversed, never idempotency_conflict (AC-51)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);

    $first = jrnReverse($original['id'], '2026-03-20', 'mistake');
    $before = jrnState();

    $again = jrnReverse($original['id'], '2026-03-20', ' mistake ');

    expect($again->replayed)->toBeTrue()
        ->and($again->id)->toBe($first->id)
        ->and($again->number)->toBe($first->number)
        ->and(jrnState())->toBe($before);

    foreach ([['2026-03-21', 'mistake'], ['2026-03-20', 'another reason']] as [$date, $reason]) {
        $refusal = jrnReverseRefusal($original['id'], $date, $reason);

        expect($refusal)->toBeInstanceOf(PostingRefused::class)
            ->and($refusal->reason)->toBe(PostingRefusal::AlreadyReversed)
            ->and($refusal->context)->toBe(['entry_id' => $first->id]);
    }

    expect(jrnState())->toBe($before);
});

it('still replays after the reversal\'s period has closed, before the gate is asked (AC-51)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);
    $first = jrnReverse($original['id'], '2026-04-10', 'mistake');

    FinanceSeed::connection()->table('fiscal_periods')->where('id', $world['periods'][4])->update(['status' => 'closed']);
    $gate = jrnSpyOnGate();

    $again = jrnReverse($original['id'], '2026-04-10', 'mistake');

    expect($again->replayed)->toBeTrue()
        ->and($again->id)->toBe($first->id)
        ->and($gate->dates)->toBe([]);
});

// ------------------------------------------------------------------ AC-52

it('refuses a reversal entry and a draft as not_reversible, and an unknown or malformed id as entry_not_found (AC-52, M11)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);
    $reversal = jrnReverse($original['id'], '2026-03-20', 'mistake');
    $draft = jrnRawEntry($world['periods'][3], ['status' => 'draft', 'source_type' => 'manual'])['id'];
    $before = jrnState();

    foreach ([$reversal->id, $draft] as $id) {
        $refusal = jrnReverseRefusal($id, '2026-03-25', 'again');

        expect($refusal)->toBeInstanceOf(PostingRefused::class)
            ->and($refusal->reason)->toBe(PostingRefusal::NotReversible);
    }

    foreach ([(string) Str::ulid(), 'not-an-id', '', '01ARZ3NDEKTSV4RRFFQ69G5FA'] as $id) {
        $refusal = jrnReverseRefusal($id, '2026-03-25', 'again');

        expect($refusal)->toBeInstanceOf(PostingRefused::class)
            ->and($refusal->reason)->toBe(PostingRefusal::EntryNotFound, var_export($id, true));
    }

    expect(jrnState())->toBe($before);
});

// ------------------------------------------------------------------ AC-53

it('refuses a missing, malformed or earlier date, accepts the same day, and never defaults (AC-53)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);
    $service = app(PostingService::class);

    $missing = null;

    try {
        jrnInTransaction(fn () => $service->reverse(ReversalRequest::fromArray(['entry_id' => $original['id'], 'reason' => 'x'], null)));
    } catch (PostingRefused $missing) {
        // expected
    }

    expect($missing)->toBeInstanceOf(PostingRefused::class)
        ->and($missing->reason)->toBe(PostingRefusal::InvalidDate);

    foreach (['2026-3-20', '2026-02-30', ' 2026-03-20', '2026-03-20 00:00:00', 20260320] as $date) {
        expect(jrnReverseRefusal($original['id'], (string) $date, 'x')->reason)->toBe(PostingRefusal::InvalidDate, (string) $date);
    }

    $earlier = jrnReverseRefusal($original['id'], '2026-03-14', 'x');

    expect($earlier->reason)->toBe(PostingRefusal::ReversalBeforeOriginal)
        ->and($earlier->field)->toBe('entry_date')
        ->and(jrnEntry($original['id'])['status'])->toBe('posted');

    expect(jrnReverse($original['id'], '2026-03-15', 'same day')->entryDate)->toBe('2026-03-15');
});

// ------------------------------------------------------------------ AC-54

it('gates only the reversal date: a closed or soft-closed target refuses and leaves the original posted; the original\'s own period is irrelevant (AC-54)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);
    $plain = FinanceSeed::actor('plain', 'finance.accounts.view');

    FinanceSeed::connection()->table('fiscal_periods')->where('id', $world['periods'][4])->update(['status' => 'closed']);
    FinanceSeed::connection()->table('fiscal_periods')->where('id', $world['periods'][5])->update(['status' => 'soft_closed']);

    $closed = jrnReverseRefusal($original['id'], '2026-04-10', 'x', $world['actor']);
    $soft = jrnReverseRefusal($original['id'], '2026-05-10', 'x', $plain);
    $softNull = jrnReverseRefusal($original['id'], '2026-05-10', 'x', null);

    expect($closed->reason)->toBe(PeriodRefusal::PeriodClosed)
        ->and($soft->reason)->toBe(PeriodRefusal::PeriodSoftClosed)
        ->and($softNull->reason)->toBe(PeriodRefusal::PeriodSoftClosed)
        ->and(jrnEntry($original['id'])['status'])->toBe('posted');

    // The adjuster may post into the soft-closed period.
    expect(jrnReverse($original['id'], '2026-05-10', 'adjusted', $world['actor'])->periodId)->toBe($world['periods'][5]);

    // The original's own period being closed does not matter.
    $other = revOriginal($world);
    FinanceSeed::connection()->table('fiscal_periods')->where('id', $world['periods'][3])->update(['status' => 'closed']);

    expect(jrnReverse($other['id'], '2026-06-10', 'later')->periodId)->toBe($world['periods'][6]);
});

// ------------------------------------------------------------------ AC-55, D-35

it('refuses a reversal when an account of the original is no longer usable, and writes nothing (AC-55, D-14)', function (string $kind, AccountRefusal $expected) {
    $world = jrnWorld();
    $original = revOriginal($world);

    match ($kind) {
        'inactive' => FinanceSeed::connection()->table('accounts')->where('id', $world['sales'])->update(['is_active' => false]),
        'deleted' => FinanceSeed::connection()->table('accounts')->where('id', $world['sales'])->update(['deleted_at' => FinanceSeed::NOW]),
        'header' => FinanceSeed::connection()->table('accounts')->where('id', $world['sales'])->update(['is_postable' => false]),
        default => throw new \InvalidArgumentException($kind),
    };

    $before = jrnState();
    $refusal = jrnReverseRefusal($original['id'], '2026-03-20', 'x');

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe($expected)
        ->and($refusal->field)->toBe('lines.1.account_id')
        ->and(jrnState())->toBe($before)
        ->and(jrnEntry($original['id'])['status'])->toBe('posted');
})->with([
    'inactive' => ['inactive', AccountRefusal::AccountInactive],
    'deleted' => ['deleted', AccountRefusal::AccountNotLive],
    'header' => ['header', AccountRefusal::AccountNotPostable],
]);

it('copies a since-deleted branch and dimensions as stored and reads none of those tables (AC-55, D-35, M22)', function () {
    $world = jrnWorld();
    $original = revOriginal($world, null);
    $deleted = '2026-06-01 00:00:00.000';

    foreach (['branches' => $original['branch'], 'locations' => $original['dims']['location_id'], 'employees' => $original['dims']['employee_id'], 'customers' => $original['dims']['customer_id'], 'products' => $original['dims']['product_id']] as $table => $id) {
        FinanceSeed::connection()->table($table)->where('id', $id)->update(['deleted_at' => $deleted]);
    }

    $reversal = null;

    // A null actor, so the only employees read there could be is the dimension's own.
    [$statements, $thrown] = jrnStatements(function () use ($original, &$reversal): void {
        $reversal = jrnReverse($original['id'], '2026-03-20', 'cleanup', null);
    });

    expect($thrown)->toBeNull();

    foreach (['branches', 'locations', 'employees', 'customers', 'products'] as $table) {
        expect(jrnStatementsOn($statements, $table))->toBe([], $table);
    }

    $line = jrnLines($reversal->id)[0];

    expect(jrnEntry($reversal->id)['branch_id'])->toBe($original['branch'])
        ->and($line['location_id'])->toBe($original['dims']['location_id'])
        ->and($line['employee_id'])->toBe($original['dims']['employee_id'])
        ->and($line['customer_id'])->toBe($original['dims']['customer_id'])
        ->and($line['product_id'])->toBe($original['dims']['product_id'])
        ->and($line['branch_id'])->toBe($original['branch']);
});

// ------------------------------------------------------------------ AC-56, AC-67

it('requires a reason of 1 to 500 characters, stored as the description and in the audit row (AC-56)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);

    foreach ([null, '', '   ', "\t\n"] as $reason) {
        expect(jrnReverseRefusal($original['id'], '2026-03-20', $reason)->reason)->toBe(PostingRefusal::ReasonRequired, var_export($reason, true));
    }

    foreach ([str_repeat('x', 501), "bad\x00reason", 12, ['x']] as $reason) {
        expect(jrnReverseRefusal($original['id'], '2026-03-20', $reason)->reason)->toBe(PostingRefusal::InvalidReason);
    }

    expect(jrnEntry($original['id'])['status'])->toBe('posted');

    $max = str_repeat('é', 500);
    $reversal = jrnReverse($original['id'], '2026-03-20', $max);

    expect(jrnEntry($reversal->id)['description'])->toBe($max);
});

it('writes exactly one audit row for a reversal, compared whole, and none for the status flip (AC-67)', function () {
    $world = jrnWorld();
    $original = revOriginal($world, $world['actor']);
    $audits = FinanceSeed::audit();

    expect($audits)->toHaveCount(1);

    $reversal = jrnReverse($original['id'], '2026-03-20', 'mistake', $world['actor']);

    $rows = array_slice(FinanceSeed::audit(), 1);

    expect($rows)->toHaveCount(1);

    $row = $rows[0];
    unset($row['id'], $row['created_at'], $row['ip']);

    foreach (['before', 'after', 'changed_fields'] as $json) {
        $row[$json] = $row[$json] === null ? null : json_decode((string) $row[$json], true);
    }

    expect($row)->toBe([
        'actor_type' => 'employee',
        'actor_id' => $world['actor'],
        'actor_name' => 'finance-admin',
        'branch_id' => null,
        'module' => 'finance',
        'action' => 'journal_entry_reversed',
        'subject_type' => 'journal_entry',
        'subject_id' => $reversal->id,
        'before' => null,
        'after' => [
            'number' => 'JE-000002',
            'source_type' => 'reversal',
            'source_id' => $original['id'],
            'source_purpose' => null,
            'entry_date' => '2026-03-20',
            'period_id' => $world['periods'][3],
            'total_debit' => '100.5000',
            'total_credit' => '100.5000',
            'line_count' => 3,
            'is_auto' => false,
            'reason' => 'mistake',
            'original_id' => $original['id'],
        ],
        'changed_fields' => ['entry_date', 'is_auto', 'line_count', 'number', 'original_id', 'period_id', 'reason', 'source_id', 'source_type', 'total_credit', 'total_debit'],
        'user_agent' => null,
        'request_id' => null,
    ]);

    // A refusal and a replay add none.
    $count = count(FinanceSeed::audit());
    jrnReverseRefusal($original['id'], '2026-03-21', 'other');
    jrnReverse($original['id'], '2026-03-20', 'mistake', $world['actor']);

    expect(count(FinanceSeed::audit()))->toBe($count);
});

// ------------------------------------------------------------------ AC-58

it('needs no finance permission at the service: an actor holding no key and a null actor both reverse (AC-58)', function () {
    $world = jrnWorld();
    $nobody = FinanceSeed::nobody();

    $first = revOriginal($world);
    $second = revOriginal($world);

    expect(jrnReverse($first['id'], '2026-03-20', 'void', $nobody)->replayed)->toBeFalse()
        ->and(jrnReverse($second['id'], '2026-03-20', 'void', null)->replayed)->toBeFalse();
});

// ------------------------------------------------------------------ AC-6a interplay

it('leaves the reversal key free when a post(source_type: reversal) naming an entry id is refused (AC-6a, D-32)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);

    $refusal = jrnPostRefusal(jrnBody($world, ['source_type' => 'reversal', 'source_id' => $original['id']]));

    expect($refusal)->toBeInstanceOf(PostingRefused::class)
        ->and($refusal->reason)->toBe(PostingRefusal::InvalidSourceType)
        ->and(FinanceSeed::connection()->table('journal_entries')->where('source_type', 'reversal')->count())->toBe(0);

    expect(jrnReverse($original['id'], '2026-03-20', 'now')->replayed)->toBeFalse();
});

// ------------------------------------------------------------------ AC-57 stand-in seam

/**
 * A `JournalRows` double that runs `$before` just before the original is locked.
 */
function revRowsDouble(Closure $before): void
{
    app()->bind(JournalRows::class, fn ($app) => new class($app->make(TenantConnection::class), $before) extends JournalRows
    {
        public function __construct(TenantConnection $tenant, private readonly Closure $before)
        {
            parent::__construct($tenant);
        }

        public function entryForUpdate(string $id): ?object
        {
            ($this->before)($id);

            return parent::entryForUpdate($id);
        }
    });
}

it('answers a reversal lost between the plain read and the lock with the winner as a replay, or already_reversed (AC-57 seam)', function (bool $samePayload) {
    $world = jrnWorld();
    $original = revOriginal($world);
    $fingerprint = $samePayload ? Fingerprint::ofReversal('2026-03-20', 'mistake') : str_repeat('b', 64);
    $winner = null;

    revRowsDouble(function (string $id) use ($world, $fingerprint, &$winner): void {
        $winner = jrnRawEntry($world['periods'][3], [
            'number' => 'JE-WINNER',
            'source_type' => 'reversal',
            'source_id' => $id,
            'reversal_of_id' => $id,
            'payload_fingerprint' => $fingerprint,
        ]);

        FinanceSeed::connection()->table('journal_entries')->where('id', $id)->update(['status' => 'reversed', 'reversed_by_id' => $winner['id']]);
    });

    [$outcome, $inside] = jrnInTransaction(function () use ($original): array {
        try {
            $result = app(PostingService::class)->reverse(ReversalRequest::fromArray(['entry_id' => $original['id'], 'entry_date' => '2026-03-20', 'reason' => 'mistake'], null));
        } catch (PostingRefused $refused) {
            $result = $refused;
        }

        return [$result, jrnState()];
    });

    if ($samePayload) {
        expect($outcome->replayed)->toBeTrue()
            ->and($outcome->id)->toBe($winner['id'])
            ->and($outcome->number)->toBe('JE-WINNER');
    } else {
        expect($outcome)->toBeInstanceOf(PostingRefused::class)
            ->and($outcome->reason)->toBe(PostingRefusal::AlreadyReversed);
    }

    // The loser wrote nothing and took no number: the one raw winner and the original are all there is.
    expect($inside['entries'])->toBe(2)
        ->and($inside['next'])->toBe(2)
        ->and($inside['audit'])->toBe(1);
})->with(['same payload' => [true], 'different payload' => [false]]);

it('refuses already_reversed when the original flips to reversed under the lock with no reversal row to replay (AC-57 seam, the status guard)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);

    revRowsDouble(function (string $id): void {
        FinanceSeed::connection()->table('journal_entries')->where('id', $id)->update(['status' => 'reversed']);
    });

    [$outcome, $inside] = jrnInTransaction(function () use ($original): array {
        try {
            $result = app(PostingService::class)->reverse(ReversalRequest::fromArray(['entry_id' => $original['id'], 'entry_date' => '2026-03-20', 'reason' => 'x'], null));
        } catch (PostingRefused $refused) {
            $result = $refused;
        }

        return [$result, jrnState()];
    });

    expect($outcome)->toBeInstanceOf(PostingRefused::class)
        ->and($outcome->reason)->toBe(PostingRefusal::AlreadyReversed)
        ->and($inside['entries'])->toBe(1)
        ->and($inside['next'])->toBe(2)
        ->and($inside['audit'])->toBe(1);
});

it('answers a lost unique-key insert of a reversal with the winner, rolling the savepoint back (AC-57 seam)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);
    $fingerprint = Fingerprint::ofReversal('2026-03-20', 'mistake');
    $winner = null;

    // The winner lands after the plain read, but the original still reads `posted`: only the unique key can stop the insert.
    revRowsDouble(function (string $id) use ($world, $fingerprint, &$winner): void {
        $winner ??= jrnRawEntry($world['periods'][3], [
            'number' => 'JE-WINNER',
            'source_type' => 'reversal',
            'source_id' => $id,
            'reversal_of_id' => $id,
            'payload_fingerprint' => $fingerprint,
        ]);
    });

    [$outcome, $inside] = jrnInTransaction(function () use ($original): array {
        $result = app(PostingService::class)->reverse(ReversalRequest::fromArray(['entry_id' => $original['id'], 'entry_date' => '2026-03-20', 'reason' => 'mistake'], null));

        return [$result, jrnState()];
    });

    expect($outcome->replayed)->toBeTrue()
        ->and($outcome->id)->toBe($winner['id'])
        ->and($inside['next'])->toBe(2)
        ->and($inside['entries'])->toBe(2);
});

it('asserts the compare-and-set changed exactly one row, and rolls the reversal back when it did not (AC-57)', function () {
    $world = jrnWorld();
    $original = revOriginal($world);

    app()->bind(JournalWriter::class, fn ($app) => new class($app->make(TenantConnection::class)) extends JournalWriter
    {
        public function markReversed(string $id, string $reversalId, string $now): int
        {
            return 0;
        }
    });

    $inside = jrnInTransaction(function () use ($original): array {
        try {
            app(PostingService::class)->reverse(ReversalRequest::fromArray(['entry_id' => $original['id'], 'entry_date' => '2026-03-20', 'reason' => 'x'], null));
        } catch (RuntimeException $failure) {
            expect($failure->getMessage())->toContain('could not be marked reversed');
        }

        return jrnState();
    });

    expect($inside['entries'])->toBe(1)
        ->and($inside['lines'])->toBe(3)
        ->and($inside['next'])->toBe(2)
        ->and($inside['audit'])->toBe(1);
});

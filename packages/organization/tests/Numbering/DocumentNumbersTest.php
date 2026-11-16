<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Numbering;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefusal;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Organization\Numbering\DatabaseDocumentNumbers;
use Flexipos\Organization\Tests\Support\LockMarkingGrammar;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 4 - DocumentNumbers (AC-40, the numbering for AC-14, AC-25 and
| AC-32; D-31, F-6, R-27)
|--------------------------------------------------------------------------
|
| The one allocator of gapless numbers, over `number_sequences`. The lock is
| pinned with LockMarkingGrammar: SQLite compiles no lock clause, so the
| statements run on a grammar that writes the clause back as a comment and
| the log shows which reads were locking reads - including the read that
| follows a lost insert race, which `pretend()` cannot reach.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function dnNumbers(): DocumentNumbers
{
    return app(DocumentNumbers::class);
}

/**
 * One sequence row, inserted directly.
 *
 * @param  array<string, mixed>  $overrides
 */
function dnSequence(string $documentType, ?string $branchId = null, array $overrides = []): string
{
    $id = strtoupper((string) Str::ulid());

    OrganizationSeed::connection()->table('number_sequences')->insert(array_merge([
        'id' => $id,
        'branch_id' => $branchId,
        'document_type' => $documentType,
        'prefix' => 'S',
        'next_number' => 1,
        'padding' => 6,
        'suffix' => null,
        'reset_period' => 'never',
        'last_reset_at' => null,
        'created_at' => OrganizationSeed::NOW,
        'updated_at' => OrganizationSeed::NOW,
    ], $overrides));

    return $id;
}

/**
 * @return array<string, mixed>|null
 */
function dnRow(string $documentType, ?string $branchId = null): ?array
{
    $query = OrganizationSeed::connection()->table('number_sequences')->where('document_type', $documentType);

    $row = $branchId === null ? $query->whereNull('branch_id')->first() : $query->where('branch_id', $branchId)->first();

    return $row === null ? null : (array) $row;
}

function dnRowCount(): int
{
    return OrganizationSeed::connection()->table('number_sequences')->count();
}

/**
 * Run a callback on the lock-marking grammar and return every statement it
 * logged against `number_sequences`.
 *
 * @return list<string>
 */
function dnLogged(callable $work): array
{
    $connection = OrganizationSeed::connection();
    $standIn = $connection->getQueryGrammar();
    $log = [];

    $connection->setQueryGrammar(new LockMarkingGrammar($connection));
    $connection->listen(static function (QueryExecuted $query) use (&$log): void {
        if (str_contains($query->sql, 'number_sequences')) {
            $log[] = $query->sql;
        }
    });

    try {
        $work();
    } finally {
        $connection->setQueryGrammar($standIn);
    }

    return $log;
}

it('binds the contract to the database allocator', function () {
    expect(dnNumbers())->toBeInstanceOf(DatabaseDocumentNumbers::class);
});

it('creates the company-wide default on first use and counts on: S000001, S000002', function () {
    expect(dnNumbers()->next('sale', null))->toBe('S000001')
        ->and(dnNumbers()->next('sale', null))->toBe('S000002');

    $row = dnRow('sale');

    expect($row)->not->toBeNull()
        ->and($row['prefix'])->toBe('S')
        ->and((int) $row['padding'])->toBe(6)
        ->and($row['reset_period'])->toBe('never')
        ->and((int) $row['next_number'])->toBe(3)
        ->and($row['branch_id'])->toBeNull()
        ->and(dnRowCount())->toBe(1);
});

it('lets a branch row win over the company row, and a branch with no row falls back to the company row', function () {
    $branchA = OrganizationSeed::branch();
    $branchB = OrganizationSeed::branch();

    dnSequence('sale', $branchA, ['prefix' => 'A-', 'padding' => 4, 'next_number' => 7]);
    dnSequence('sale', null, ['prefix' => 'S', 'next_number' => 40]);

    expect(dnNumbers()->next('sale', $branchA))->toBe('A-0007')
        ->and(dnNumbers()->next('sale', $branchB))->toBe('S000040')
        ->and(dnNumbers()->next('sale', null))->toBe('S000041')
        // The branch row moved, and the company row moved only for the two calls that used it.
        ->and((int) dnRow('sale', $branchA)['next_number'])->toBe(8)
        ->and((int) dnRow('sale')['next_number'])->toBe(42);
});

it('accepts the branch id in lower case, as it is stored upper case', function () {
    $branch = OrganizationSeed::branch();

    dnSequence('sale', $branch, ['prefix' => 'A-', 'padding' => 4]);

    expect(dnNumbers()->next('sale', strtolower($branch)))->toBe('A-0001');
});

it('keeps sale, refund and cash_shift independent, with prefixes that differ', function () {
    expect(dnNumbers()->next('sale', null))->toBe('S000001')
        ->and(dnNumbers()->next('refund', null))->toBe('R000001')
        ->and(dnNumbers()->next('cash_shift', null))->toBe('SH000001')
        ->and(dnNumbers()->next('sale', null))->toBe('S000002')
        ->and(dnNumbers()->next('refund', null))->toBe('R000002')
        ->and(dnRowCount())->toBe(3);
});

it('refuses a sequence that resets, as unsupported_reset_period, and changes nothing', function (string $period) {
    dnSequence('sale', null, ['reset_period' => $period, 'next_number' => 5]);
    $before = dnRow('sale');

    try {
        dnNumbers()->next('sale', null);
        Assert::fail('Expected a refusal.');
    } catch (DocumentNumbersRefused $refused) {
        expect($refused->reason)->toBe(DocumentNumbersRefusal::UnsupportedResetPeriod)
            ->and($refused->reason->value)->toBe('unsupported_reset_period');
    }

    expect(dnRow('sale'))->toBe($before);
})->with(['yearly', 'monthly', 'daily', 'Never']);

it('leaves next_number where it was when the surrounding transaction rolls back', function () {
    $connection = OrganizationSeed::connection();

    expect(dnNumbers()->next('sale', null))->toBe('S000001');

    $connection->beginTransaction();

    expect(dnNumbers()->next('sale', null))->toBe('S000002');

    $connection->rollBack();

    expect((int) dnRow('sale')['next_number'])->toBe(2)
        ->and(dnNumbers()->next('sale', null))->toBe('S000002');
});

it('creates the default inside a transaction that rolls back, and the next caller creates it again', function () {
    $connection = OrganizationSeed::connection();

    $connection->beginTransaction();
    dnNumbers()->next('refund', null);
    $connection->rollBack();

    expect(dnRowCount())->toBe(0)
        ->and(dnNumbers()->next('refund', null))->toBe('R000001');
});

it('reads every sequence row FOR UPDATE, before it writes anything', function () {
    $branch = OrganizationSeed::branch();
    dnSequence('sale', $branch, ['prefix' => 'A-']);

    $log = dnLogged(function () use ($branch) {
        dnNumbers()->next('sale', $branch);
        dnNumbers()->next('sale', OrganizationSeed::branch());
    });

    $reads = array_values(array_filter($log, static fn (string $sql): bool => str_starts_with($sql, 'select')));

    expect($reads)->not->toBe([])
        ->and(array_filter($reads, static fn (string $sql): bool => ! str_contains($sql, LockMarkingGrammar::FOR_UPDATE)))->toBe([])
        // And the first statement of the whole allocation is a locking read.
        ->and($log[0])->toStartWith('select')
        ->and($log[0])->toContain(LockMarkingGrammar::FOR_UPDATE);
});

it('survives a lost first-use race: the loser retries with a locking read and takes the next number', function () {
    $connection = OrganizationSeed::connection();
    $raced = false;
    $competitor = strtoupper((string) Str::ulid());

    // The moment the allocator's first read of number_sequences has come back
    // empty, another transaction inserts the company row.
    $connection->listen(function (QueryExecuted $query) use ($connection, &$raced, $competitor): void {
        if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'number_sequences')) {
            return;
        }

        $raced = true;

        $connection->table('number_sequences')->insert([
            'id' => $competitor, 'branch_id' => null, 'document_type' => 'sale', 'prefix' => 'S', 'next_number' => 1,
            'padding' => 6, 'suffix' => null, 'reset_period' => 'never', 'last_reset_at' => null,
            'created_at' => OrganizationSeed::NOW, 'updated_at' => OrganizationSeed::NOW,
        ]);
    });

    $number = null;

    $log = dnLogged(function () use (&$number) {
        $number = dnNumbers()->next('sale', null);
    });

    $reads = array_values(array_filter($log, static fn (string $sql): bool => str_starts_with($sql, 'select')));

    expect($raced)->toBeTrue()
        ->and($number)->toBe('S000001')
        ->and(dnRowCount())->toBe(1)
        ->and((int) dnRow('sale')['next_number'])->toBe(2)
        // Two reads (the empty one and the retry), and the retry is a locking read (R-27).
        ->and(count($reads))->toBeGreaterThanOrEqual(2)
        ->and(array_filter($reads, static fn (string $sql): bool => ! str_contains($sql, LockMarkingGrammar::FOR_UPDATE)))->toBe([]);
});

it('writes reset_period never and padding 6 explicitly on the default row', function () {
    dnNumbers()->next('cash_shift', null);

    $row = dnRow('cash_shift');

    expect($row['reset_period'])->toBe('never')
        ->and((int) $row['padding'])->toBe(6)
        ->and($row['prefix'])->toBe('SH')
        ->and($row['suffix'])->toBeNull();
});

it('refuses a number that would exceed the 30-character column as number_too_long, and changes nothing', function () {
    // 25 + 6 = 31.
    dnSequence('sale', null, ['prefix' => str_repeat('P', 25), 'padding' => 6, 'next_number' => 12]);
    $before = dnRow('sale');

    try {
        dnNumbers()->next('sale', null);
        Assert::fail('Expected a refusal.');
    } catch (DocumentNumbersRefused $refused) {
        expect($refused->reason)->toBe(DocumentNumbersRefusal::NumberTooLong);
    }

    expect(dnRow('sale'))->toBe($before);

    // The suffix counts too: 24 + 6 + 1 = 31.
    OrganizationSeed::connection()->table('number_sequences')->where('document_type', 'sale')->update(['prefix' => str_repeat('P', 24), 'suffix' => 'X']);

    expect(fn () => dnNumbers()->next('sale', null))->toThrow(DocumentNumbersRefused::class);

    // Exactly 30 is allowed: 23 + 6 + 1.
    OrganizationSeed::connection()->table('number_sequences')->where('document_type', 'sale')->update(['prefix' => str_repeat('P', 23)]);

    expect(strlen(dnNumbers()->next('sale', null)))->toBe(30);
});

it('gives a document type it has no prefix for a bare padded number', function () {
    expect(dnNumbers()->next('quotation', null))->toBe('000001');
});

it('opens no transaction of its own: it numbers inside the one the caller is in', function () {
    $connection = OrganizationSeed::connection();

    dnNumbers()->next('sale', null);

    expect($connection->transactionLevel())->toBe(0);

    $connection->beginTransaction();
    dnNumbers()->next('sale', null);

    expect($connection->transactionLevel())->toBe(1);

    $connection->rollBack();
});

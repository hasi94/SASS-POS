<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\OpenCashShift;

use Closure;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefusal;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\OpenCashShift\OpenCashShift;
use Flexipos\Sales\Features\OpenCashShift\OpenCashShiftHandler;
use Flexipos\Sales\Features\OpenCashShift\OpenCashShiftResult;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use PDOException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 12 - OpenCashShift (AC-32, AC-50; D-22; Ruling 7; R-39)
|--------------------------------------------------------------------------
|
| One open shift per terminal, the branch from the terminal and never from the
| caller, the number from DocumentNumbers (`SH000001`), allocated last. The
| owed deadlock is handled by retrying the whole transaction once, and that is
| proved here against a numbering fake that throws the engine's deadlock error.
*/

uses(TestCase::class);

require_once __DIR__.'/../../Support/sales-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    salesEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $fields
 */
function openCashShift(array $fields, ?string $actorId): OpenCashShiftResult
{
    /** @var OpenCashShiftHandler $handler */
    $handler = app(OpenCashShiftHandler::class);

    return $handler->handle(new OpenCashShift($fields, $actorId));
}

/**
 * @return array{branch: string, terminal: string, location: string, cashier: string}
 */
function shiftTill(): array
{
    $branch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);

    return ['branch' => $branch, 'terminal' => $terminal['id'], 'location' => $terminal['location'], 'cashier' => SalesSeed::employee('cashier')];
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function shiftInput(array $w, array $overrides = []): array
{
    return array_merge(['terminal_id' => $w['terminal'], 'opening_cash' => '100'], $overrides);
}

/**
 * Replace DocumentNumbers with a wrapper around the real one that runs `$before` on every call first.
 *
 * @param  Closure(int): void  $before  given the 1-based call number
 * @return object{calls: int}
 */
function numberingWrapper(Closure $before): object
{
    $real = app(DocumentNumbers::class);

    $wrapper = new class($real, $before) implements DocumentNumbers
    {
        public int $calls = 0;

        public function __construct(private readonly DocumentNumbers $real, private readonly Closure $before) {}

        public function next(string $documentType, ?string $branchId): string
        {
            $this->calls++;
            ($this->before)($this->calls);

            return $this->real->next($documentType, $branchId);
        }
    };

    app()->instance(DocumentNumbers::class, $wrapper);

    return $wrapper;
}

function deadlock(): QueryException
{
    return new QueryException(
        'tenant',
        'select * from "number_sequences" where "branch_id_key" = ? for update',
        [],
        new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')
    );
}

it('opens a shift: open, numbered SH000001, the branch from the terminal, the cashier from the actor, and one audit row', function () {
    $w = shiftTill();

    $result = openCashShift(shiftInput($w), strtolower($w['cashier']));

    expect($result->number)->toBe('SH000001');

    $row = SalesSeed::row('cash_shifts', $result->shiftId);

    expect($row)->toMatchArray([
        'status' => 'open',
        'number' => 'SH000001',
        'branch_id' => $w['branch'],
        'terminal_id' => $w['terminal'],
        'employee_id' => $w['cashier'],
        'opening_cash' => '100.0000',
        'opened_at' => '2026-11-12 10:00:00.000',
        'closed_at' => null,
        'expected_cash' => null,
        'counted_cash' => null,
        'variance' => null,
        'closed_by' => null,
        'reconciled_by' => null,
        'open_terminal_key' => $w['terminal'],
    ]);

    expect(SalesSeed::onlyAuditRow())->toMatchArray([
        'module' => 'sales',
        'action' => 'sales_cash_shift_opened',
        'subject_type' => 'cash_shift',
        'subject_id' => $result->shiftId,
        'actor_id' => $w['cashier'],
        'before' => null,
    ]);
});

it('numbers the next shift on another terminal SH000002', function () {
    $w = shiftTill();
    $second = SalesSeed::terminal($w['branch'], 'T2');

    expect(openCashShift(shiftInput($w), $w['cashier'])->number)->toBe('SH000001')
        ->and(openCashShift(shiftInput($w, ['terminal_id' => $second['id']]), $w['cashier'])->number)->toBe('SH000002');
});

it('refuses a second open shift on the terminal, and writes nothing for it', function () {
    $w = shiftTill();
    openCashShift(shiftInput($w), $w['cashier']);

    $audits = count(SalesSeed::audit());

    SalesRefusals::expect(SalesRefusal::ShiftAlreadyOpen, fn () => openCashShift(shiftInput($w), $w['cashier']));

    expect(SalesSeed::connection()->table('cash_shifts')->count())->toBe(1)
        ->and(count(SalesSeed::audit()))->toBe($audits)
        // The refused attempt took no number.
        ->and((int) SalesSeed::connection()->table('number_sequences')->value('next_number'))->toBe(2);
});

it('refuses the second open shift before it touches the number sequence, so the hot lock is never taken for it', function () {
    $w = shiftTill();
    openCashShift(shiftInput($w), $w['cashier']);

    $log = SalesQueryLog::during(fn () => SalesRefusals::capture(fn () => openCashShift(shiftInput($w), $w['cashier'])));

    expect(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'number_sequences')))->toBe([]);
});

it('opens a new shift once the terminal\'s previous one has closed', function (string $status) {
    $w = shiftTill();
    SalesSeed::shift($w['terminal'], $w['branch'], $w['cashier'], 'SH000099', ['status' => $status]);

    expect(openCashShift(shiftInput($w), $w['cashier'])->shiftId)->toBeString();
})->with(['closed' => ['closed'], 'reconciled' => ['reconciled'], 'closing' => ['closing']]);

it('is not_found for a terminal that is unknown, pending, paused, revoked, credential-revoked or deleted (AC-32)', function (string $case) {
    $w = shiftTill();
    $terminals = SalesSeed::connection()->table('terminals')->where('id', $w['terminal']);

    match ($case) {
        'unknown' => $w['terminal'] = '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        'pending' => $terminals->update(['status' => 'pending']),
        'paused' => $terminals->update(['status' => 'inactive']),
        'revoked' => $terminals->update(['status' => 'revoked']),
        'credential revoked' => SalesSeed::connection()->table('terminal_credentials')->where('terminal_id', $w['terminal'])->update(['status' => 'revoked']),
        'deleted' => $terminals->update(['deleted_at' => SalesSeed::NOW]),
        default => throw new InvalidArgumentException("Unknown case [{$case}]."),
    };

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => openCashShift(shiftInput($w), $w['cashier']));

    expect(SalesSeed::connection()->table('cash_shifts')->count())->toBe(0);
})->with(['unknown', 'pending', 'paused', 'revoked', 'credential revoked', 'deleted']);

it('re-checks the terminal under its lock: one paused or deleted after the authority was asked is not_found', function (string $change) {
    $w = shiftTill();
    $real = app(TerminalAuthority::class);

    // The authority answers "active", and the terminal changes before the transaction locks it.
    app()->instance(TerminalAuthority::class, new class($real, $w['terminal'], $change) implements TerminalAuthority
    {
        public function __construct(private readonly TerminalAuthority $real, private readonly string $terminal, private readonly string $change) {}

        public function activeBranchOf(string $terminalId): ?string
        {
            $branch = $this->real->activeBranchOf($terminalId);

            SalesSeed::connection()->table('terminals')->where('id', $this->terminal)->update(
                $this->change === 'paused' ? ['status' => 'inactive'] : ['deleted_at' => SalesSeed::NOW]
            );

            return $branch;
        }

        public function isPaused(string $terminalId): bool
        {
            return $this->real->isPaused($terminalId);
        }

        public function recordSeen(string $terminalId): void
        {
            $this->real->recordSeen($terminalId);
        }
    });

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => openCashShift(shiftInput($w), $w['cashier']));

    expect(SalesSeed::connection()->table('cash_shifts')->count())->toBe(0);
})->with(['paused', 'deleted']);

it('refuses an opening cash that is missing, negative, over-precise, over-wide or not a string (R-39)', function (mixed $cash) {
    $w = shiftTill();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => openCashShift(shiftInput($w, ['opening_cash' => $cash]), $w['cashier']));
})->with(['null' => [null], 'negative' => ['-1'], 'five decimals' => ['1.00005'], 'fifteen integer digits' => ['100000000000000'], 'a float' => [1.5], 'text' => ['lots']]);

it('accepts an opening cash of zero and the widest the column holds', function (string $cash) {
    $w = shiftTill();

    $result = openCashShift(shiftInput($w, ['opening_cash' => $cash]), $w['cashier']);

    // (The stand-in hands a DECIMAL back as a number, and a float cannot hold the widest value, so the
    // stored figure is checked for the values it can: the point here is that the input is accepted.)
    expect($result->shiftId)->toBeString()
        ->and(SalesSeed::row('cash_shifts', $result->shiftId)['status'])->toBe('open');
})->with(['0', '99999999999999.9999', '12.5']);

it('stores the opening cash as four decimals', function () {
    $w = shiftTill();

    $captured = [];
    SalesSeed::connection()->listen(static function ($query) use (&$captured): void {
        if (str_starts_with($query->sql, 'insert into "cash_shifts"')) {
            $captured = $query->bindings;
        }
    });

    openCashShift(shiftInput($w, ['opening_cash' => '12.5']), $w['cashier']);

    expect($captured)->toContain('12.5000');
});

it('refuses a branch, an employee, a number, a status or any time the caller supplies', function (string $field) {
    $w = shiftTill();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => openCashShift(shiftInput($w, [$field => 'x']), $w['cashier']));
})->with(['branch_id', 'employee_id', 'number', 'status', 'opened_at', 'closed_at', 'expected_cash', 'device_id', 'client_created_at', 'origin']);

it('needs a live cashier, and writes nothing without one', function () {
    $w = shiftTill();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => openCashShift(shiftInput($w), null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => openCashShift(shiftInput($w), '01HZZZZZZZZZZZZZZZZZZZZZZZ'));

    expect(SalesSeed::connection()->table('cash_shifts')->count())->toBe(0)
        ->and(SalesSeed::connection()->table('number_sequences')->count())->toBe(0);
});

it('takes the terminal row FOR UPDATE first, and allocates its number last (Ruling 7, row 7)', function () {
    $w = shiftTill();

    $log = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => openCashShift(shiftInput($w), $w['cashier'])));

    expect($log[0])->toStartWith('select')
        ->and($log[0])->toContain('"terminals"')
        ->and($log[0])->toContain(LockMarkingGrammar::FOR_UPDATE);

    $firstNumbering = null;
    $insert = null;

    foreach ($log as $position => $sql) {
        if ($firstNumbering === null && str_contains($sql, 'number_sequences')) {
            $firstNumbering = $position;
        }
        if (str_starts_with($sql, 'insert into "cash_shifts"')) {
            $insert = $position;
        }
    }

    $locksAfterNumbering = array_filter(
        array_slice($log, (int) $firstNumbering + 1),
        static fn (string $sql): bool => str_contains($sql, LockMarkingGrammar::FOR_UPDATE) && ! str_contains($sql, 'number_sequences')
    );

    expect($firstNumbering)->not->toBeNull()
        ->and($insert)->toBeGreaterThan($firstNumbering)
        // After the sequence row is locked, no other row is.
        ->and($locksAfterNumbering)->toBe([]);
});

it('refuses numbering_refused, carrying the cause, and writes nothing (AC-50)', function (string $value) {
    $w = shiftTill();
    $cause = DocumentNumbersRefusal::from($value);

    numberingWrapper(function () use ($cause): void {
        throw $cause === DocumentNumbersRefusal::NumberTooLong
            ? DocumentNumbersRefused::numberTooLong('too long')
            : DocumentNumbersRefused::unsupportedResetPeriod('yearly');
    });

    $refused = SalesRefusals::expect(SalesRefusal::NumberingRefused, fn () => openCashShift(shiftInput($w), $w['cashier']));

    expect($refused->cause)->toBe($cause)
        ->and(SalesSeed::connection()->table('cash_shifts')->count())->toBe(0)
        ->and(SalesSeed::audit())->toBe([]);
})->with(['number_too_long', 'unsupported_reset_period']);

it('refuses a yearly sequence through the real numbering, naming the cause', function () {
    $w = shiftTill();

    SalesSeed::connection()->table('number_sequences')->insert([
        'id' => '01HZZZZZZZZZZZZZZZZZZZZZZY', 'branch_id' => null, 'document_type' => 'cash_shift', 'prefix' => 'SH', 'next_number' => 1,
        'padding' => 6, 'suffix' => null, 'reset_period' => 'yearly', 'last_reset_at' => null, 'created_at' => SalesSeed::NOW, 'updated_at' => SalesSeed::NOW,
    ]);

    $refused = SalesRefusals::expect(SalesRefusal::NumberingRefused, fn () => openCashShift(shiftInput($w), $w['cashier']));

    expect($refused->cause)->toBe(DocumentNumbersRefusal::UnsupportedResetPeriod);
});

it('retries the whole transaction once when the numbering deadlocks, and the second attempt succeeds (NOT VERIFIED on a real engine)', function () {
    $w = shiftTill();

    $wrapper = numberingWrapper(function (int $call): void {
        if ($call === 1) {
            throw deadlock();
        }
    });

    $result = openCashShift(shiftInput($w), $w['cashier']);

    expect($wrapper->calls)->toBe(2)
        ->and($result->number)->toBe('SH000001')
        // One shift, one number consumed, one audit row: the first attempt left nothing behind.
        ->and(SalesSeed::connection()->table('cash_shifts')->count())->toBe(1)
        ->and((int) SalesSeed::connection()->table('number_sequences')->value('next_number'))->toBe(2)
        ->and(SalesSeed::audit())->toHaveCount(1);
});

it('gives up after the second deadlock, surfacing the engine error with nothing written', function () {
    $w = shiftTill();

    $wrapper = numberingWrapper(function (): void {
        throw deadlock();
    });

    expect(fn () => openCashShift(shiftInput($w), $w['cashier']))->toThrow(QueryException::class, 'Deadlock found');

    expect($wrapper->calls)->toBe(2)
        ->and(SalesSeed::connection()->table('cash_shifts')->count())->toBe(0)
        ->and(SalesSeed::audit())->toBe([]);
});

it('does not retry an error that is not a deadlock', function () {
    $w = shiftTill();

    $wrapper = numberingWrapper(function (): void {
        throw new QueryException('tenant', 'select 1', [], new PDOException('SQLSTATE[HY000]: General error: 1 no such table'));
    });

    expect(fn () => openCashShift(shiftInput($w), $w['cashier']))->toThrow(QueryException::class);

    expect($wrapper->calls)->toBe(1);
});

it('refuses shift_already_open when the unique key catches a rival the pre-check could not see (D-22)', function () {
    $w = shiftTill();
    $rival = SalesSeed::employee('rival');

    // Between this transaction's pre-check and its insert, another till opens a shift on the terminal.
    numberingWrapper(function () use ($w, $rival): void {
        SalesSeed::shift($w['terminal'], $w['branch'], $rival, 'SH000050');
    });

    SalesRefusals::expect(SalesRefusal::ShiftAlreadyOpen, fn () => openCashShift(shiftInput($w), $w['cashier']));

    // (The rival here shares this transaction, so it rolls back with it; the refusal is the point.)
    expect(SalesSeed::audit())->toBe([]);
});

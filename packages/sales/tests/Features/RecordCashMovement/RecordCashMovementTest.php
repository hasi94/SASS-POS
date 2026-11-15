<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Features\RecordCashMovement;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Features\RecordCashMovement\RecordCashMovement;
use Flexipos\Sales\Features\RecordCashMovement\RecordCashMovementHandler;
use Flexipos\Sales\Features\RecordCashMovement\RecordCashMovementResult;
use Flexipos\Sales\Shifts\MovementType;
use Flexipos\Sales\Tests\Support\LockMarkingGrammar;
use Flexipos\Sales\Tests\Support\SalesQueryLog;
use Flexipos\Sales\Tests\Support\SalesRefusals;
use Flexipos\Sales\Tests\Support\SalesSeed;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-030 step 12 - RecordCashMovement (AC-33, AC-37; D-39; Ruling 6, 7; R-8, R-39)
|--------------------------------------------------------------------------
|
| Seven types, the amount stored positive, the caller's ULID as the row's own id
| and so the idempotency key: the same payload replays to the original with no
| second audit row, a different payload under the id is `replay_conflict`, and
| the comparison of the amount is numeric. The shift is read under a shared lock
| first and must be open.
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
function recordCashMovement(array $fields, ?string $actorId): RecordCashMovementResult
{
    /** @var RecordCashMovementHandler $handler */
    $handler = app(RecordCashMovementHandler::class);

    return $handler->handle(new RecordCashMovement($fields, $actorId));
}

/**
 * @return array{shift: string, cashier: string, branch: string, terminal: string}
 */
function movementShift(string $status = 'open'): array
{
    $branch = SalesSeed::branch();
    $terminal = SalesSeed::terminal($branch);
    $cashier = SalesSeed::employee('cashier');

    return [
        'shift' => SalesSeed::shift($terminal['id'], $branch, $cashier, 'SH000001', ['status' => $status]),
        'cashier' => $cashier,
        'branch' => $branch,
        'terminal' => $terminal['id'],
    ];
}

/**
 * @param  array<string, mixed>  $w
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function movementInput(array $w, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(),
        'shift_id' => $w['shift'],
        'type' => 'cash_in',
        'amount' => '25',
        'reason' => 'Float top-up',
    ], $overrides);
}

it('records a movement against an open shift: the caller\'s id, the amount stored positive, the actor as employee, one audit row', function () {
    $w = movementShift();
    $input = movementInput($w, ['id' => strtolower((string) Str::ulid()), 'reference' => 'ENV-7']);

    $result = recordCashMovement($input, $w['cashier']);

    expect($result->replayed)->toBeFalse()
        ->and($result->movementId)->toBe(strtoupper($input['id']));

    expect(SalesSeed::row('cash_movements', $result->movementId))->toMatchArray([
        'shift_id' => $w['shift'],
        'type' => 'cash_in',
        'amount' => '25.0000',
        'reason' => 'Float top-up',
        'reference' => 'ENV-7',
        'employee_id' => $w['cashier'],
        'approved_by' => null,
        'created_at' => '2026-11-12 10:00:00.000',
    ]);

    expect(SalesSeed::onlyAuditRow())->toMatchArray([
        'module' => 'sales',
        'action' => 'sales_cash_movement_recorded',
        'subject_type' => 'cash_movement',
        'subject_id' => $result->movementId,
        'actor_id' => $w['cashier'],
        'before' => null,
    ]);
});

it('accepts each of the seven types', function (string $type) {
    $w = movementShift();

    $result = recordCashMovement(movementInput($w, ['type' => $type]), $w['cashier']);

    expect(SalesSeed::row('cash_movements', $result->movementId)['type'])->toBe($type);
})->with(MovementType::values());

it('refuses a type that is not one of the seven, including by case', function (mixed $type) {
    $w = movementShift();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => recordCashMovement(movementInput($w, ['type' => $type]), $w['cashier']));
})->with(['unknown' => ['refund'], 'upper case' => ['CASH_IN'], 'blank' => [''], 'null' => [null]]);

it('refuses an amount that is zero, negative, over-precise, over-wide, a float or text (R-39)', function (mixed $amount) {
    $w = movementShift();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => recordCashMovement(movementInput($w, ['amount' => $amount]), $w['cashier']));
})->with(['zero' => ['0'], 'zero point' => ['0.0000'], 'negative' => ['-5'], 'five decimals' => ['1.00005'], 'fifteen digits' => ['100000000000000'], 'a float' => [1.5], 'text' => ['ten'], 'null' => [null]]);

it('refuses a missing or malformed id and shift id, and a blank, over-long reason or reference', function (array $overrides) {
    $w = movementShift();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => recordCashMovement(movementInput($w, $overrides), $w['cashier']));
})->with([
    'no id' => [['id' => null]],
    'a malformed id' => [['id' => 'abc']],
    'a malformed shift' => [['shift_id' => 'abc']],
    'a blank reason' => [['reason' => '  ']],
    'a 256 character reason' => [['reason' => str_repeat('r', 256)]],
    'a 101 character reference' => [['reference' => str_repeat('x', 101)]],
]);

it('refuses fields it does not take: an account, an approver, an employee, a branch, a time', function (string $field) {
    $w = movementShift();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => recordCashMovement(movementInput($w, [$field => 'x']), $w['cashier']));
})->with(['financial_account_id', 'approved_by', 'employee_id', 'branch_id', 'created_at', 'device_id', 'client_created_at', 'terminal_id']);

it('is not_found for an unknown shift', function () {
    $w = movementShift();

    SalesRefusals::expect(SalesRefusal::NotFound, fn () => recordCashMovement(movementInput($w, ['shift_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']), $w['cashier']));
});

it('refuses shift_not_open for a shift that is closing, closed or reconciled, and writes nothing', function (string $status) {
    $w = movementShift($status);

    SalesRefusals::expect(SalesRefusal::ShiftNotOpen, fn () => recordCashMovement(movementInput($w), $w['cashier']));

    expect(SalesSeed::connection()->table('cash_movements')->count())->toBe(0)
        ->and(SalesSeed::audit())->toBe([]);
})->with(['closing' => ['closing'], 'closed' => ['closed'], 'reconciled' => ['reconciled']]);

it('needs a live employee as the actor', function () {
    $w = movementShift();

    SalesRefusals::expect(SalesRefusal::Validation, fn () => recordCashMovement(movementInput($w), null));
    SalesRefusals::expect(SalesRefusal::NotFound, fn () => recordCashMovement(movementInput($w), '01HZZZZZZZZZZZZZZZZZZZZZZZ'));
});

it('replays an identical payload to the original with no second row and no second audit row, comparing the amount by value (AC-37, R-8)', function () {
    $w = movementShift();
    $input = movementInput($w, ['amount' => '10.0000', 'reference' => 'R1']);

    $first = recordCashMovement($input, $w['cashier']);

    // "10" is "10.0000", and the id may come back in lower case.
    $again = recordCashMovement(array_merge($input, ['amount' => '10', 'id' => strtolower($input['id'])]), $w['cashier']);

    expect($again->replayed)->toBeTrue()
        ->and($again->movementId)->toBe($first->movementId)
        ->and(SalesSeed::connection()->table('cash_movements')->count())->toBe(1)
        ->and(SalesSeed::audit())->toHaveCount(1);
});

it('refuses replay_conflict when any part of the payload differs under the same id', function (array $change) {
    $w = movementShift();
    $other = SalesSeed::terminal($w['branch'], 'T2');
    $otherShift = SalesSeed::shift($other['id'], $w['branch'], $w['cashier'], 'SH000002');
    $input = movementInput($w, ['reference' => 'R1']);

    recordCashMovement($input, $w['cashier']);

    $payload = array_merge($input, $change);

    if (($change['shift_id'] ?? null) === '@other') {
        $payload['shift_id'] = $otherShift;
    }

    $actor = ($change['actor'] ?? null) === '@other' ? SalesSeed::employee('other') : $w['cashier'];
    unset($payload['actor']);

    SalesRefusals::expect(SalesRefusal::ReplayConflict, fn () => recordCashMovement($payload, $actor));

    expect(SalesSeed::connection()->table('cash_movements')->count())->toBe(1)
        ->and(SalesSeed::audit())->toHaveCount(1);
})->with([
    'a different amount' => [['amount' => '10.0001']],
    'a different type' => [['type' => 'cash_out']],
    'a different reason' => [['reason' => 'Something else']],
    'a different reference' => [['reference' => 'R2']],
    'no reference' => [['reference' => null]],
    'a different shift' => [['shift_id' => '@other']],
    'a different employee' => [['actor' => '@other']],
]);

it('replays a movement after its shift has closed, but refuses a new one', function () {
    $w = movementShift();
    $input = movementInput($w);

    $first = recordCashMovement($input, $w['cashier']);

    SalesSeed::connection()->table('cash_shifts')->where('id', $w['shift'])->update(['status' => 'closed']);

    expect(recordCashMovement($input, $w['cashier'])->replayed)->toBeTrue()
        ->and(recordCashMovement($input, $w['cashier'])->movementId)->toBe($first->movementId);

    SalesRefusals::expect(SalesRefusal::ShiftNotOpen, fn () => recordCashMovement(movementInput($w), $w['cashier']));
});

it('resolves a lost race on the id: a competing insert between the read and the write replays or conflicts (AC-37)', function (string $rivalAmount, bool $conflicts) {
    $w = movementShift();
    $input = movementInput($w, ['amount' => '10']);

    $armed = true;
    SalesSeed::connection()->listen(function (QueryExecuted $query) use (&$armed, $w, $input, $rivalAmount): void {
        if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"cash_movements"')) {
            $armed = false;
            SalesSeed::movement($w['shift'], $w['cashier'], 'cash_in', $rivalAmount, ['id' => strtoupper($input['id']), 'reason' => 'Float top-up']);
        }
    });

    if ($conflicts) {
        SalesRefusals::expect(SalesRefusal::ReplayConflict, fn () => recordCashMovement($input, $w['cashier']));

        return;
    }

    expect(recordCashMovement($input, $w['cashier'])->replayed)->toBeTrue()
        ->and(SalesSeed::connection()->table('cash_movements')->count())->toBe(1);
})->with([
    'the rival wrote the same payload' => ['10.0000', false],
    'the rival wrote a different one' => ['11.0000', true],
]);

it('re-reads the winner of a lost race under FOR UPDATE, never with a plain read (T-028 review W-1)', function () {
    $w = movementShift();
    $input = movementInput($w, ['amount' => '10']);

    $armed = true;
    SalesSeed::connection()->listen(function (QueryExecuted $query) use (&$armed, $w, $input): void {
        if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"cash_movements"')) {
            $armed = false;
            SalesSeed::movement($w['shift'], $w['cashier'], 'cash_in', '10.0000', ['id' => strtoupper($input['id']), 'reason' => 'Float top-up']);
        }
    });

    $log = SalesQueryLog::during(fn () => recordCashMovement($input, $w['cashier']));

    $reads = array_values(array_filter($log, static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, '"cash_movements"')));

    // The first read (by id, before the write) is plain; the one after the lost insert locks.
    expect($reads)->toHaveCount(2)
        ->and($reads[0])->not->toContain(LockMarkingGrammar::FOR_UPDATE)
        ->and($reads[1])->toContain(LockMarkingGrammar::FOR_UPDATE);
});

it('takes the shift row under a shared lock as its first statement, by primary key (Ruling 7, row 1)', function () {
    $w = movementShift();

    $log = SalesQueryLog::inTransaction(SalesQueryLog::during(fn () => recordCashMovement(movementInput($w), $w['cashier'])));

    expect($log[0])->toStartWith('select')
        ->and($log[0])->toContain('"cash_shifts"')
        ->and($log[0])->toContain('"id" = ?')
        ->and($log[0])->toContain(LockMarkingGrammar::SHARED)
        ->and($log[0])->not->toContain('"status"');
});

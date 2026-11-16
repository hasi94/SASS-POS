<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RecordCashMovement;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Shifts\MovementType;
use Flexipos\Sales\Shifts\ShiftStatus;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Record a cash movement (T-030 AC-33, AC-37; D-39; Ruling 6 and 7).
 *
 * **The caller's ULID is the row's own id and so the idempotency key.** A replay
 * with the same payload returns the original (`replayed = true`) and writes
 * nothing, not even an audit row; a different payload under the id is
 * `replay_conflict`. The payload is the shift, the type, the amount, the reason,
 * the reference and the employee, and the amount is compared by value (`10` is
 * `10.0000`, R-8). The replay check runs **before** the open-shift check, so a
 * retry that arrives after the shift closed still gets its original answer; only
 * a new movement is refused `shift_not_open`.
 *
 * **The shift row is the first statement, under a shared lock, by primary key**
 * (Ruling 7, row 1): a close takes the same row exclusively, so the close's
 * `expected_cash` can never miss a movement that committed after it read. Two
 * movements share the lock and so do not serialise against each other. The
 * status is re-checked after the lock.
 *
 * **A lost race on the id** (two requests with one id, both past the read) is the
 * primary key's to refuse: the violation is caught and the winner's row is read
 * again **under a lock**, because on MySQL a plain re-read after the failed insert
 * would see the old snapshot and find nothing (T-028 review W-1's class).
 *
 * The `amount` is stored positive; its sign is a property of the type (D-39).
 * `financial_account_id` and `approved_by` are not fields: the first is not a
 * built column (D-6), the second belongs to a flow this task does not ship.
 */
final class RecordCashMovementHandler
{
    public const ACTION = 'sales_cash_movement_recorded';

    private const FIELDS = ['id', 'shift_id', 'type', 'amount', 'reason', 'reference'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(RecordCashMovement $command): RecordCashMovementResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A cash movement is recorded by an employee.');
        }

        $id = Identifier::fold($fields['id'] ?? null, 'id');
        $shiftId = Identifier::fold($fields['shift_id'] ?? null, 'shift_id');
        $type = Fields::enum($fields, 'type', MovementType::values(), null) ?? throw SalesRefused::validation('The type is required.');
        $amount = Money::of(Fields::positiveMoney($fields, 'amount'));
        $reason = Fields::requiredText($fields, 'reason', 255);
        $reference = Fields::optionalText($fields, 'reference', 100);
        $employeeId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($employeeId)) {
            throw SalesRefused::notFound("No employee has id [{$employeeId}].");
        }

        $wanted = [
            'shift_id' => $shiftId,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'reference' => $reference,
            'employee_id' => $employeeId,
        ];

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $id, $shiftId, $wanted, $employeeId): RecordCashMovementResult {
            $shift = Lookup::any($connection, 'cash_shifts', $shiftId, 'cash shift', Lookup::SHARED);

            $existing = $connection->table('cash_movements')->where('id', $id)->first();

            if ($existing !== null) {
                return $this->replay($existing, $wanted);
            }

            if ((string) $shift->status !== ShiftStatus::Open->value) {
                throw SalesRefused::because(SalesRefusal::ShiftNotOpen, "Cash shift [{$shiftId}] is [{$shift->status}], not open.");
            }

            try {
                $connection->table('cash_movements')->insert([
                    'id' => $id,
                    'shift_id' => $shiftId,
                    'type' => $wanted['type'],
                    'amount' => $wanted['amount'],
                    'reason' => $wanted['reason'],
                    'reference' => $wanted['reference'],
                    'employee_id' => $employeeId,
                    'created_at' => $this->clock->timestamp(),
                ]);
            } catch (UniqueConstraintViolationException $violation) {
                $winner = $connection->table('cash_movements')->where('id', $id)->lockForUpdate()->first();

                if ($winner === null) {
                    throw $violation;
                }

                return $this->replay($winner, $wanted);
            }

            $row = $connection->table('cash_movements')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $employeeId, self::ACTION, 'cash_movement', $id, null, $row === null ? null : (array) $row, strtoupper((string) $shift->branch_id));

            return new RecordCashMovementResult($id, false);
        });
    }

    /**
     * The original row for an identical payload, `replay_conflict` for any other.
     *
     * @param  array<string, string|null>  $wanted
     */
    private function replay(object $existing, array $wanted): RecordCashMovementResult
    {
        $same = strtoupper((string) $existing->shift_id) === $wanted['shift_id']
            && (string) $existing->type === $wanted['type']
            && Money::equals(Money::of($existing->amount), (string) $wanted['amount'])
            && (string) $existing->reason === $wanted['reason']
            && ($existing->reference === null ? null : (string) $existing->reference) === $wanted['reference']
            && strtoupper((string) $existing->employee_id) === $wanted['employee_id'];

        if (! $same) {
            throw SalesRefused::because(SalesRefusal::ReplayConflict, "Cash movement [{$existing->id}] already exists with a different payload.");
        }

        return new RecordCashMovementResult(strtoupper((string) $existing->id), true);
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ReconcileCashShift;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Registry\SalesPermissions;
use Flexipos\Sales\Shifts\ShiftStatus;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\TenantConnection;

/**
 * Mark a closed cash shift reconciled (T-030 AC-36, AC-37; D-21, D-30, D-50, D-69;
 * Ruling 7).
 *
 * **A status marker only** (D-21): it compares nothing against any external record.
 * Any employee holding `sales.shifts.reconcile` **at the shift's own branch** may do
 * it, whether or not they closed the shift (a key held at the null scope covers every
 * branch; a key held at another branch only does not): otherwise `not_authorised`.
 * The permission is checked **first**, before the shift's state is looked at, so a
 * caller without it learns nothing about whether the shift is closed or reconciled.
 * The branch comes from the shift row, read plainly for that one purpose; the row is
 * then locked and decides everything else.
 *
 * **Lock order.** The shift `FOR UPDATE`, by primary key, is the transaction's first
 * statement, as `CloseCashShift`'s is. A `closed` shift becomes `reconciled`, with
 * `reconciled_by`; an already `reconciled` one is a plain no-op that keeps whoever did
 * it first and writes no audit row (AC-37); an `open` one is `shift_not_open`.
 */
final class ReconcileCashShiftHandler
{
    public const ACTION = 'sales_cash_shift_reconciled';

    private const FIELDS = ['shift_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(ReconcileCashShift $command): ReconcileCashShiftResult
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A shift is reconciled by an employee.');
        }

        $shiftId = Identifier::fold($command->fields['shift_id'] ?? null, 'shift_id');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $connection = $this->tenant->get();

        $seen = Lookup::any($connection, 'cash_shifts', $shiftId, 'cash shift');
        $branchId = strtoupper((string) $seen->branch_id);

        if (! $this->permissions->decide($actorId, SalesPermissions::SHIFTS_RECONCILE, $branchId)->allowed) {
            throw SalesRefused::because(SalesRefusal::NotAuthorised, 'The employee may not reconcile cash shifts at this branch.');
        }

        return $connection->transaction(function () use ($connection, $shiftId, $actorId, $branchId): ReconcileCashShiftResult {
            $shift = Lookup::any($connection, 'cash_shifts', $shiftId, 'cash shift', true);
            $status = (string) $shift->status;

            if ($status === ShiftStatus::Reconciled->value) {
                return new ReconcileCashShiftResult($shiftId, $status, true);
            }

            if ($status !== ShiftStatus::Closed->value) {
                throw SalesRefused::because(SalesRefusal::ShiftNotOpen, "Cash shift [{$shiftId}] is [{$status}] and cannot be reconciled: close it first.");
            }

            $now = $this->clock->timestamp();

            $connection->table('cash_shifts')->where('id', $shiftId)->update([
                'status' => ShiftStatus::Reconciled->value,
                'reconciled_by' => $actorId,
                'updated_at' => $now,
            ]);

            $after = $connection->table('cash_shifts')->where('id', $shiftId)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'cash_shift', $shiftId, (array) $shift, $after === null ? null : (array) $after, $branchId);

            return new ReconcileCashShiftResult($shiftId, ShiftStatus::Reconciled->value, false);
        });
    }
}

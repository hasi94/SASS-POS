<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\OpenCashShift;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Shifts\ShiftStatus;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Open a cash shift (T-030 AC-32, AC-50; D-22; Ruling 7).
 *
 * **The branch comes from the terminal** (`TerminalAuthority::activeBranchOf()`),
 * never from the caller, and a terminal that is unknown, pending, paused, revoked,
 * soft-deleted or whose credential is revoked is `not_found`. The cashier is the
 * actor.
 *
 * **One open shift per terminal, twice over.** A pre-check reads for an open
 * shift and refuses `shift_already_open`; the stored `open_terminal_key` unique
 * column is the backstop that a race cannot get past, and a violation of it (and
 * only of it: a duplicate number is not this refusal) is refused the same way.
 *
 * **Lock order (Ruling 7).** The terminal row is the transaction's first
 * statement, a locking read by primary key, so two openings for one terminal
 * serialise instead of racing to the index; a plain read first would fix the
 * snapshot. The number comes **last**: `number_sequences` is the hottest lock in
 * the shop and is held to commit, so nothing else is locked after it. This slice
 * takes no shift and no sale lock, because the shift it creates is new.
 *
 * **A deadlock is retried, once.** `DatabaseDocumentNumbers` takes a locking read
 * of a key that may not exist yet, and under InnoDB's REPEATABLE READ two
 * concurrent first uses can deadlock rather than reach the unique key. That is
 * not measured here (NOT VERIFIED: it needs the engine, and the engine hold is
 * on). What this does about it is `transaction(..., 2)`: the engine's deadlock
 * error rolls the whole transaction back and runs it again once, so nothing of
 * the first attempt (the shift, the audit row, the consumed number) survives, and
 * a second deadlock reaches the caller as the engine's own error. It retries only
 * when this is the outermost transaction; a caller that opens one around it takes
 * the retry onto itself.
 */
final class OpenCashShiftHandler
{
    public const ACTION = 'sales_cash_shift_opened';

    private const FIELDS = ['terminal_id', 'opening_cash'];

    private const ATTEMPTS = 2;

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly TerminalAuthority $terminals,
        private readonly EmployeeDirectory $employees,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function handle(OpenCashShift $command): OpenCashShiftResult
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A shift is opened by an employee.');
        }

        $terminalId = Identifier::fold($command->fields['terminal_id'] ?? null, 'terminal_id');
        $openingCash = Money::of(Fields::requiredMoney($command->fields, 'opening_cash'));
        $employeeId = Identifier::fold($command->actorId, 'actor');

        $branchId = $this->terminals->activeBranchOf($terminalId);

        if ($branchId === null) {
            throw SalesRefused::notFound("No active terminal has id [{$terminalId}].");
        }

        $branchId = strtoupper($branchId);

        if (! $this->employees->isLive($employeeId)) {
            throw SalesRefused::notFound("No employee has id [{$employeeId}].");
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $terminalId, $branchId, $employeeId, $openingCash): OpenCashShiftResult {
            $terminal = $connection->table('terminals')->where('id', $terminalId)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($terminal === null || (string) $terminal->status !== 'active' || strtoupper((string) $terminal->branch_id) !== $branchId) {
                throw SalesRefused::notFound("No active terminal has id [{$terminalId}].");
            }

            if ($connection->table('cash_shifts')->where('terminal_id', $terminalId)->where('status', ShiftStatus::Open->value)->exists()) {
                throw SalesRefused::because(SalesRefusal::ShiftAlreadyOpen, "Terminal [{$terminalId}] already has an open shift.");
            }

            try {
                $number = $this->numbers->next('cash_shift', $branchId);
            } catch (DocumentNumbersRefused $refused) {
                throw SalesRefused::because(SalesRefusal::NumberingRefused, $refused->getMessage(), $refused->reason);
            }

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            try {
                $connection->table('cash_shifts')->insert([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'terminal_id' => $terminalId,
                    'branch_id' => $branchId,
                    'employee_id' => $employeeId,
                    'number' => $number,
                    'status' => ShiftStatus::Open->value,
                    'opened_at' => $now,
                    'opening_cash' => $openingCash,
                ]);
            } catch (UniqueConstraintViolationException $violation) {
                if (str_contains($violation->getMessage(), 'open_terminal_key')) {
                    throw SalesRefused::because(SalesRefusal::ShiftAlreadyOpen, "Terminal [{$terminalId}] already has an open shift.");
                }

                throw $violation;
            }

            $row = $connection->table('cash_shifts')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $employeeId, self::ACTION, 'cash_shift', $id, null, $row === null ? null : (array) $row, $branchId);

            return new OpenCashShiftResult($id, $number);
        }, self::ATTEMPTS);
    }
}

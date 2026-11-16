<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RecallSale;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\SaleLocks;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Support\Str;

/**
 * Recall a held sale (T-030 AC-18, AC-19, AC-51; D-24, D-25, D-39; F-15; R-5).
 *
 * The sale returns to **exactly the status recorded when it was held**. It
 * moves to the recalling terminal and to that terminal's open shift, so a cash
 * tender taken on it afterwards is counted in the drawer that takes it, and a
 * terminal with no open shift cannot recall (`no_open_shift`). The cashier on the
 * sale is never changed. A second `sale_holds` row (`action = recall`) records
 * who picked it up and where.
 *
 * **Lock order (R-5).** The recalling terminal's open shift id is read before the
 * transaction; the first statement locks that shift (shared, by id) and checks
 * again that it is open and the terminal's, then the sale is locked `FOR UPDATE`:
 * a close that committed in between leaves `no_open_shift` and no sale attributed
 * to a closed drawer.
 */
final class RecallSaleHandler
{
    public const ACTION = 'sales_sale_recalled';

    private const FIELDS = ['sale_id', 'terminal_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly TerminalAuthority $terminals,
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(RecallSale $command): RecallSaleResult
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A sale is recalled by an employee.');
        }

        $saleId = Identifier::fold($command->fields['sale_id'] ?? null, 'sale_id');
        $terminalId = Identifier::fold($command->fields['terminal_id'] ?? null, 'terminal_id');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $terminalBranch = $this->terminals->activeBranchOf($terminalId);

        if ($terminalBranch === null) {
            throw SalesRefused::notFound("No active terminal has id [{$terminalId}].");
        }

        $terminalBranch = strtoupper($terminalBranch);
        $connection = $this->tenant->get();

        $shiftId = SaleLocks::openShiftIdOf($connection, $terminalId)
            ?? throw SalesRefused::because(SalesRefusal::NoOpenShift, "Terminal [{$terminalId}] has no open cash shift.");

        return $connection->transaction(function () use ($connection, $saleId, $terminalId, $terminalBranch, $shiftId, $actorId): RecallSaleResult {
            SaleLocks::lockOpenShift($connection, $shiftId, $terminalId);

            $sale = SaleLocks::lockSale($connection, $saleId);
            $status = (string) $sale->status;

            if (strtoupper((string) $sale->branch_id) !== $terminalBranch) {
                throw SalesRefused::notFound("No sale has id [{$saleId}] at the branch of terminal [{$terminalId}].");
            }

            if ($status !== SaleStatus::Held->value) {
                throw SalesRefused::because(SalesRefusal::InvalidTransition, "Sale [{$saleId}] is [{$status}] and is not held.");
            }

            $restored = SaleLocks::heldFrom($connection, $saleId)
                ?? throw SalesRefused::because(SalesRefusal::InvalidTransition, "Sale [{$saleId}] has no recorded hold to return to.");

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $connection->table('sale_holds')->insert([
                'id' => $id,
                'sale_id' => $saleId,
                'action' => 'recall',
                'from_status' => SaleStatus::Held->value,
                'to_status' => $restored,
                'terminal_id' => $terminalId,
                'employee_id' => $actorId,
                'created_at' => $now,
            ]);

            $connection->table('sales')->where('id', $saleId)->update([
                'status' => $restored,
                'terminal_id' => $terminalId,
                'shift_id' => $shiftId,
                'updated_at' => $now,
            ]);

            $after = $connection->table('sales')->where('id', $saleId)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'sale', $saleId, (array) $sale, $after === null ? null : (array) $after, strtoupper((string) $sale->branch_id));

            return new RecallSaleResult($id, $restored, $shiftId);
        });
    }
}

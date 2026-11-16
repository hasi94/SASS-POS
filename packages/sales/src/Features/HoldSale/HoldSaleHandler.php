<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\HoldSale;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\SaleLocks;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Sales\SaleType;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Support\Str;

/**
 * Park a sale (T-030 AC-17, AC-19, AC-51; D-24, D-25; Ruling 4).
 *
 * A `draft` or a `pending_payment` sale becomes `held`, and the status it was held
 * from is recorded in a `sale_holds` row, which is what the line-edit rule (AC-6)
 * and the tender rule (AC-11) read once the sale is recalled. Any other status is
 * `invalid_transition`, naming it. Holding takes no shift: it does not move the
 * sale between drawers, so the only lock is the sale itself, taken first.
 *
 * The terminal is where the sale is being parked; it must be an active terminal of
 * the sale's own branch, so a till of another branch cannot park (or later see) it.
 * The cashier on the sale is never changed (D-24).
 */
final class HoldSaleHandler
{
    public const ACTION = 'sales_sale_held';

    private const FIELDS = ['sale_id', 'terminal_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly TerminalAuthority $terminals,
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(HoldSale $command): HoldSaleResult
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A sale is held by an employee.');
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

        return $connection->transaction(function () use ($connection, $saleId, $terminalId, $terminalBranch, $actorId): HoldSaleResult {
            $sale = SaleLocks::lockSale($connection, $saleId);
            $status = (string) $sale->status;

            if (strtoupper((string) $sale->branch_id) !== $terminalBranch) {
                throw SalesRefused::notFound("No sale has id [{$saleId}] at the branch of terminal [{$terminalId}].");
            }

            if ((string) $sale->type !== SaleType::Sale->value || ! in_array($status, [SaleStatus::Draft->value, SaleStatus::PendingPayment->value], true)) {
                throw SalesRefused::because(SalesRefusal::InvalidTransition, "Sale [{$saleId}] is [{$status}] and cannot be held.");
            }

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $connection->table('sale_holds')->insert([
                'id' => $id,
                'sale_id' => $saleId,
                'action' => 'hold',
                'from_status' => $status,
                'to_status' => SaleStatus::Held->value,
                'terminal_id' => $terminalId,
                'employee_id' => $actorId,
                'created_at' => $now,
            ]);

            $connection->table('sales')->where('id', $saleId)->update(['status' => SaleStatus::Held->value, 'updated_at' => $now]);

            $after = $connection->table('sales')->where('id', $saleId)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'sale', $saleId, (array) $sale, $after === null ? null : (array) $after, strtoupper((string) $sale->branch_id));

            return new HoldSaleResult($id, $status);
        });
    }
}

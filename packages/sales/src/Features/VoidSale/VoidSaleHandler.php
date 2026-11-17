<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\VoidSale;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Approvals\ApprovalBinding;
use Flexipos\Sales\Approvals\ApprovalGate;
use Flexipos\Sales\Approvals\ApprovalType;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\SaleLocks;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Sales\SaleType;
use Flexipos\Sales\Shifts\ShiftStatus;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;
use Flexipos\Sales\Tenders\CreditPosting;
use Flexipos\Sales\Tenders\MethodType;
use Illuminate\Support\Str;

/**
 * Void a sale (T-030 AC-21, AC-22, AC-24A, AC-48; D-14 to D-16, D-29, D-39, D-47,
 * D-66, D-72; Ruling 7).
 *
 * **Before completion** (`draft`, `held`, `pending_payment`) a void needs no
 * approval: nothing financially final has happened (D-14). **A completed sale**
 * needs a decided `void` approval bound to the sale and to the sale's own branch
 * (the gate is called with the sale's `branch_id`, never a caller-supplied one,
 * D-72), and its own shift must still be `open` (D-39): a mirror tender on a shift
 * that has been counted and closed would never reach any drawer count, so the
 * refusal is `shift_closed` and the cashier is directed to a refund.
 *
 * **Every tender is reversed by a mirror, never edited** (D-16): one new row per
 * original, opposite sign, the same method, `tendered` null and no change. The
 * original rows are not touched. A credit tender is compensated in the customer
 * ledger only when the sale had completed, because only then was a `credit_sale`
 * posted (F-3): a `credit_note` keyed on the **mirror's** id, carrying a positive
 * credit equal to the mirror's magnitude (F-4, R-20). A posting the ledger reports
 * as replayed is refused (R-4, D-66). The sale's rolled-up figures are left as they
 * were, so a voided sale still shows what it had been. Discount usage is not
 * released (D-37). No stock or general-ledger effect (D-1, D-6, D-16).
 *
 * **Lock order (R-5), completed sales.** The sale's own `shift_id` is read before
 * the transaction. The transaction's first statement locks that shift
 * `sharedLock()` by id and checks it is still `open`; then the sale `FOR UPDATE`,
 * which checks its status and `shift_id` again; then the approval; then the
 * customers (inside the ledger). A `CloseCashShift` that commits between the read
 * and the lock leaves `shift_closed` and no mirror on the closed shift. An earlier
 * sale needs no shift lock: it counts toward no figure the close reads, and a close
 * refuses to run while one is open. No number is allocated, so there is no deadlock
 * retry here.
 */
final class VoidSaleHandler
{
    public const ACTION = 'sales_sale_voided';

    private const FIELDS = ['sale_id', 'reason', 'approval_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
        private readonly ApprovalGate $approvals,
        private readonly CreditPosting $credit,
    ) {}

    public function handle(VoidSale $command): VoidSaleResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A sale is voided by an employee.');
        }

        $saleId = Identifier::fold($fields['sale_id'] ?? null, 'sale_id');
        $reason = Fields::requiredText($fields, 'reason', 255);
        $approvalId = Identifier::foldNullable($fields['approval_id'] ?? null, 'approval_id');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $connection = $this->tenant->get();

        // A plain read: it decides which locks to take, and nothing else. The locked row decides everything.
        $seen = Lookup::any($connection, 'sales', $saleId, 'sale');
        $this->assertVoidable($seen);

        $wasCompleted = (string) $seen->status === SaleStatus::Completed->value;
        $shiftId = $seen->shift_id === null ? null : strtoupper((string) $seen->shift_id);

        return $connection->transaction(function () use ($connection, $saleId, $reason, $approvalId, $actorId, $wasCompleted, $shiftId): VoidSaleResult {
            if ($wasCompleted) {
                $shift = $shiftId === null ? null : $connection->table('cash_shifts')->where('id', $shiftId)->sharedLock()->first();

                if ($shift === null || (string) $shift->status !== ShiftStatus::Open->value) {
                    throw SalesRefused::because(SalesRefusal::ShiftClosed, "The shift of sale [{$saleId}] is closed: refund the sale instead.");
                }
            }

            $sale = SaleLocks::lockSale($connection, $saleId);
            $this->assertVoidable($sale);

            $completed = (string) $sale->status === SaleStatus::Completed->value;

            if ($completed !== $wasCompleted || ($completed && strtoupper((string) $sale->shift_id) !== $shiftId)) {
                throw SalesRefused::because(SalesRefusal::InvalidTransition, "Sale [{$saleId}] changed while it was being voided; retry it.");
            }

            $branchId = strtoupper((string) $sale->branch_id);
            $approvalRequestId = null;

            if ($completed) {
                $approvalRequestId = $this->approvals->consume(ApprovalType::Void, $branchId, ApprovalBinding::SALE, $saleId, null, $approvalId)->approvalId;
            }

            $now = $this->clock->timestamp();
            $originals = $connection->table('sale_tenders')->where('sale_id', $saleId)->orderBy('paid_at')->orderBy('id')->get();
            $mirrors = [];

            foreach ($originals as $original) {
                $mirrorId = (string) Str::ulid();
                $amount = Money::negate(Money::of($original->amount));

                $connection->table('sale_tenders')->insert([
                    'id' => $mirrorId,
                    'sale_id' => $saleId,
                    'payment_method_id' => strtoupper((string) $original->payment_method_id),
                    'method_code' => $original->method_code,
                    'method_type' => $original->method_type,
                    'amount' => $amount,
                    'tendered' => null,
                    'change_given' => Money::ZERO,
                    'reference' => $original->reference,
                    'payment_status' => 'recorded',
                    'paid_at' => $now,
                ]);

                $mirrors[] = $mirrorId;

                if ($completed && (string) $original->method_type === MethodType::Credit->value) {
                    $this->credit->creditNote($sale, $mirrorId, $amount, $actorId);
                }
            }

            $connection->table('sales')->where('id', $saleId)->update([
                'status' => SaleStatus::Voided->value,
                'voided_by' => $actorId,
                'void_reason' => $reason,
                'voided_at' => $now,
                'approval_request_id' => $approvalRequestId,
                'updated_at' => $now,
            ]);

            $after = $connection->table('sales')->where('id', $saleId)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'sale', $saleId, (array) $sale, $after === null ? null : (array) $after, $branchId);

            return new VoidSaleResult($saleId, $mirrors);
        });
    }

    /**
     * @throws SalesRefused sale_not_voidable, invalid_transition
     */
    private function assertVoidable(object $sale): void
    {
        $status = (string) $sale->status;

        if ((string) $sale->type !== SaleType::Sale->value || in_array($status, [SaleStatus::Refunded->value, SaleStatus::PartiallyRefunded->value], true)) {
            throw SalesRefused::because(SalesRefusal::SaleNotVoidable, "Document [{$sale->id}] is a {$sale->type} in [{$status}] and cannot be voided.");
        }

        if (! in_array($status, [SaleStatus::Draft->value, SaleStatus::Held->value, SaleStatus::PendingPayment->value, SaleStatus::Completed->value], true)) {
            throw SalesRefused::because(SalesRefusal::InvalidTransition, "Sale [{$sale->id}] is [{$status}] and cannot be voided.");
        }
    }
}

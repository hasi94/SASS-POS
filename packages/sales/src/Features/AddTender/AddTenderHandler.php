<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\AddTender;

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
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;
use Flexipos\Sales\Tenders\MethodType;
use Flexipos\Sales\Tenders\TenderRules;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Record a tender (T-030 AC-11, AC-12, AC-37, AC-56; D-29, D-51, D-60; F-22;
 * Ruling 6 and 7).
 *
 * **The caller sends what was put on the counter** (`offered`): the tendered
 * figure for a method that gives change, the amount for any other. The amount,
 * the tendered figure and the change are what {@see TenderRules} makes of it
 * against the sale's balance, and are refused as unknown fields if sent.
 *
 * **Idempotency by the caller's id.** The row's own id is the key. A replay is
 * decided **right after the sale lock and before any state check**, so a retry
 * that arrives after the sale completed still gets its original answer, and the
 * retry of a tender that picked a held sale up writes no second `recall` row.
 * What is compared is the request (the sale, the method, the reference and the
 * figure offered, by value), never the outcome, which depends on a balance the
 * first call changed. An id already used by a tender of **another sale** is
 * `replay_conflict`. A tender since removed is simply added again (AC-56, D-60:
 * there is no tombstone).
 *
 * **A sale held from `pending_payment` is recalled by tendering it** (F-22,
 * D-51): in the same transaction a `recall` row is written, the sale moves to the
 * tendering terminal and that terminal's open shift, and it stays
 * `pending_payment` with the tender recorded. A sale held from `draft` is
 * refused (`sale_not_editable`: recall it first). No ledger entry is posted
 * here: credit is posted at completion (D-29, F-3), so removing a tender never
 * leaves one behind.
 *
 * **Lock order (R-5).** The tendering terminal's open shift (shared, by id,
 * checked again) comes first and only for the implicit recall, then the sale
 * `FOR UPDATE`, then the payment method `sharedLock()`, which is what stops a
 * tender landing on a method a concurrent delete has just found unused.
 */
final class AddTenderHandler
{
    public const ACTION = 'sales_tender_added';

    private const FIELDS = ['id', 'sale_id', 'payment_method_id', 'offered', 'reference', 'terminal_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly TerminalAuthority $terminals,
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(AddTender $command): AddTenderResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A tender is taken by an employee.');
        }

        $id = Identifier::fold($fields['id'] ?? null, 'id');
        $saleId = Identifier::fold($fields['sale_id'] ?? null, 'sale_id');
        $methodId = Identifier::fold($fields['payment_method_id'] ?? null, 'payment_method_id');
        $offered = Money::of(Fields::positiveMoney($fields, 'offered'));
        $reference = Fields::optionalText($fields, 'reference', 100);
        $terminalId = Identifier::foldNullable($fields['terminal_id'] ?? null, 'terminal_id');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $connection = $this->tenant->get();

        // Whether this call will pick a held sale up is read before the transaction, because the shift it will be
        // attributed to is the first lock (Ruling 7). The sale is read again, locked, inside.
        $recallShiftId = $this->recallShift($connection, $saleId, $terminalId);

        $wanted = ['sale_id' => $saleId, 'payment_method_id' => $methodId, 'reference' => $reference, 'offered' => $offered];

        return $connection->transaction(function () use ($connection, $id, $saleId, $methodId, $offered, $reference, $terminalId, $recallShiftId, $actorId, $wanted): AddTenderResult {
            if ($recallShiftId !== null && $terminalId !== null) {
                SaleLocks::lockOpenShift($connection, $recallShiftId, $terminalId);
            }

            $sale = SaleLocks::lockSale($connection, $saleId);

            $existing = $connection->table('sale_tenders')->where('id', $id)->first();

            if ($existing !== null) {
                return $this->replay($existing, $wanted);
            }

            $recalling = $this->assertTenderable($connection, $sale, $saleId, $recallShiftId);

            $method = Lookup::live($connection, 'payment_methods', $methodId, 'payment method', Lookup::SHARED);

            $paid = Money::sum($connection->table('sale_tenders')->where('sale_id', $saleId)->pluck('amount')->all());
            $balance = Money::subtract(Money::of($sale->total), $paid);

            $outcome = TenderRules::decide($method, $offered, $reference, $balance);

            if ((string) $method->type === MethodType::Credit->value && $sale->customer_id === null) {
                throw SalesRefused::because(SalesRefusal::CustomerRequired, 'A credit tender needs a sale with a customer.');
            }

            $now = $this->clock->timestamp();

            if ($recalling) {
                $this->recall($connection, $sale, (string) $terminalId, (string) $recallShiftId, $actorId, $now);
            } elseif ((string) $sale->status === SaleStatus::Draft->value) {
                $connection->table('sales')->where('id', $saleId)->update(['status' => SaleStatus::PendingPayment->value, 'updated_at' => $now]);
            }

            try {
                $connection->table('sale_tenders')->insert([
                    'id' => $id,
                    'sale_id' => $saleId,
                    'payment_method_id' => strtoupper((string) $method->id),
                    'method_code' => $method->code,
                    'method_type' => $method->type,
                    'amount' => $outcome->amount,
                    'tendered' => $outcome->tendered,
                    'change_given' => $outcome->changeGiven,
                    'reference' => $reference,
                    'payment_status' => 'recorded',
                    'paid_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // An id that another sale's tender holds: the sale lock does not serialise two sales.
                throw SalesRefused::because(SalesRefusal::ReplayConflict, "Tender [{$id}] already exists with a different payload.");
            }

            $row = $connection->table('sale_tenders')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'sale_tender', $id, null, $row === null ? null : (array) $row, strtoupper((string) $sale->branch_id));

            return new AddTenderResult($id, false, $outcome->amount, $outcome->tendered, $outcome->changeGiven);
        });
    }

    /**
     * The id of the open shift of the terminal that is picking a held sale up,
     * or null when this call is not a pick-up.
     *
     * @throws SalesRefused not_found, no_open_shift, validation
     */
    private function recallShift(Connection $connection, string $saleId, ?string $terminalId): ?string
    {
        $sale = Lookup::any($connection, 'sales', $saleId, 'sale');

        if ((string) $sale->status !== SaleStatus::Held->value || SaleLocks::heldFrom($connection, $saleId) !== SaleStatus::PendingPayment->value) {
            return null;
        }

        if ($terminalId === null) {
            throw SalesRefused::validation('A tender on a held sale needs the terminal_id it is taken at.');
        }

        $branchId = $this->terminals->activeBranchOf($terminalId);

        if ($branchId === null || strtoupper($branchId) !== strtoupper((string) $sale->branch_id)) {
            throw SalesRefused::notFound("No active terminal of the sale's branch has id [{$terminalId}].");
        }

        return SaleLocks::openShiftIdOf($connection, $terminalId)
            ?? throw SalesRefused::because(SalesRefusal::NoOpenShift, "Terminal [{$terminalId}] has no open cash shift.");
    }

    /**
     * Whether the sale, locked, can take a tender, and whether doing so picks it up from a hold.
     *
     * @throws SalesRefused sale_not_editable, invalid_transition
     */
    private function assertTenderable(Connection $connection, object $sale, string $saleId, ?string $recallShiftId): bool
    {
        $status = (string) $sale->status;

        if ((string) $sale->type !== SaleType::Sale->value) {
            throw SalesRefused::because(SalesRefusal::SaleNotEditable, "Document [{$saleId}] is not a sale.");
        }

        if ($status === SaleStatus::Held->value) {
            if (SaleLocks::heldFrom($connection, $saleId) !== SaleStatus::PendingPayment->value) {
                throw SalesRefused::because(SalesRefusal::SaleNotEditable, "Sale [{$saleId}] is held from draft: recall it before tendering.");
            }

            if ($recallShiftId === null) {
                throw SalesRefused::because(SalesRefusal::InvalidTransition, "Sale [{$saleId}] was held while this tender was being recorded; retry it.");
            }

            return true;
        }

        if (! in_array($status, [SaleStatus::Draft->value, SaleStatus::PendingPayment->value], true)) {
            throw SalesRefused::because(SalesRefusal::SaleNotEditable, "Sale [{$saleId}] is [{$status}] and takes no tender.");
        }

        return false;
    }

    /**
     * The implicit recall (AC-18's own reassignment, F-22): a `recall` row, and the sale
     * moved to the tendering terminal and its open shift, back to `pending_payment`.
     */
    private function recall(Connection $connection, object $sale, string $terminalId, string $shiftId, string $actorId, string $now): void
    {
        $connection->table('sale_holds')->insert([
            'id' => (string) Str::ulid(),
            'sale_id' => strtoupper((string) $sale->id),
            'action' => 'recall',
            'from_status' => SaleStatus::Held->value,
            'to_status' => SaleStatus::PendingPayment->value,
            'terminal_id' => $terminalId,
            'employee_id' => $actorId,
            'created_at' => $now,
        ]);

        $connection->table('sales')->where('id', $sale->id)->update([
            'status' => SaleStatus::PendingPayment->value,
            'terminal_id' => $terminalId,
            'shift_id' => $shiftId,
            'updated_at' => $now,
        ]);
    }

    /**
     * The original row for an identical request, `replay_conflict` for any other.
     *
     * @param  array{sale_id: string, payment_method_id: string, reference: ?string, offered: string}  $wanted
     */
    private function replay(object $existing, array $wanted): AddTenderResult
    {
        $offered = Money::of($existing->tendered ?? $existing->amount);

        $same = strtoupper((string) $existing->sale_id) === $wanted['sale_id']
            && strtoupper((string) $existing->payment_method_id) === $wanted['payment_method_id']
            && ($existing->reference === null ? null : (string) $existing->reference) === $wanted['reference']
            && Money::equals($offered, $wanted['offered']);

        if (! $same) {
            throw SalesRefused::because(SalesRefusal::ReplayConflict, "Tender [{$existing->id}] already exists with a different payload.");
        }

        return new AddTenderResult(
            strtoupper((string) $existing->id),
            true,
            Money::of($existing->amount),
            $existing->tendered === null ? null : Money::of($existing->tendered),
            Money::of($existing->change_given),
        );
    }
}

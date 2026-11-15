<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CloseCashShift;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Sales\SaleType;
use Flexipos\Sales\Shifts\ShiftStatus;
use Flexipos\Sales\Shifts\ShiftTotals;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Database\Connection;
use JsonException;

/**
 * Close a cash shift (T-030 AC-34, AC-35, AC-37; D-21, D-39, D-50; Ruling 7, R-5, R-8).
 *
 * **The shift row is the transaction's first statement, `FOR UPDATE`, by primary
 * key.** Every write that attributes anything to a shift (`CreateSale`,
 * `RecallSale`, `AddTender` on a recall, `RecordCashMovement`, a completed-sale
 * `VoidSale`, `RefundSale`) takes the same row shared and re-checks it is `open`,
 * so each of them either commits before this takes the lock, and is read below
 * (nothing was read before the lock, so every read here is fresh), or waits and is
 * refused once this commits. Nothing is silently attached to a closed shift.
 *
 * **What a close computes** (AC-34, AC-35, D-39):
 *
 * - `expected_cash` is {@see ShiftTotals::expectedCash()};
 * - `variance = counted_cash - expected_cash`, negative when the drawer is short;
 * - `sales_count`, `gross`, `discounts` and `tax` are over this shift's `type =
 *   sale` rows that are `completed`, `refunded` or `partially_refunded`
 *   (a voided sale counts for nothing);
 * - `refunds_count` is every `type = refund` row on this shift, and `net` is the
 *   sales' total less the refunds' total;
 * - `totals_by_method` maps each payment-method code to the summed, **signed**
 *   tender `amount` over those same documents (the refunds', negative, included),
 *   never `tendered`, which would count change already paid back out.
 *
 * A `draft` or `pending_payment` sale on the shift refuses the close
 * (`shift_has_open_sales`); a `held` one does not. Nothing is posted to a general
 * ledger (D-6) and no number is taken.
 *
 * **Idempotent by the shift's own id** (AC-37, D-50). A close replayed against a
 * shift that is already `closed` or `reconciled` with the same `counted_cash`,
 * compared by value (`100` is `100.0000`, R-8), returns the stored figures and
 * writes nothing; a different `counted_cash` is `replay_conflict`.
 */
final class CloseCashShiftHandler
{
    public const ACTION = 'sales_cash_shift_closed';

    private const FIELDS = ['shift_id', 'counted_cash'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(CloseCashShift $command): CloseCashShiftResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A shift is closed by an employee.');
        }

        $shiftId = Identifier::fold($fields['shift_id'] ?? null, 'shift_id');
        $counted = Money::of(Fields::requiredMoney($fields, 'counted_cash'));
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $shiftId, $counted, $actorId): CloseCashShiftResult {
            $shift = Lookup::any($connection, 'cash_shifts', $shiftId, 'cash shift', true);
            $status = (string) $shift->status;

            if (in_array($status, [ShiftStatus::Closed->value, ShiftStatus::Reconciled->value], true)) {
                if ($shift->counted_cash === null || ! Money::equals(Money::of($shift->counted_cash), $counted)) {
                    throw SalesRefused::because(SalesRefusal::ReplayConflict, "Cash shift [{$shiftId}] is already {$status} with a different counted cash.");
                }

                return $this->stored($shift, true);
            }

            if ($status !== ShiftStatus::Open->value) {
                throw SalesRefused::because(SalesRefusal::ShiftNotOpen, "Cash shift [{$shiftId}] is [{$status}], not open.");
            }

            $open = $connection->table('sales')
                ->where('shift_id', $shiftId)
                ->whereIn('status', [SaleStatus::Draft->value, SaleStatus::PendingPayment->value])
                ->count();

            if ($open > 0) {
                throw SalesRefused::because(SalesRefusal::ShiftHasOpenSales, "Cash shift [{$shiftId}] has {$open} open sale(s): complete or void them first.");
            }

            $expected = ShiftTotals::expectedCash($connection, $shiftId);
            $variance = Money::subtract($counted, $expected);

            $figures = $this->figures($connection, $shiftId);

            foreach ([$expected, $variance, $figures['gross'], $figures['discounts'], $figures['tax'], $figures['net']] as $amount) {
                if (! Money::fits($amount)) {
                    throw SalesRefused::outOfRange("A figure of cash shift [{$shiftId}] would not fit DECIMAL(18,4).");
                }
            }

            $now = $this->clock->timestamp();

            $connection->table('cash_shifts')->where('id', $shiftId)->update([
                'status' => ShiftStatus::Closed->value,
                'closed_at' => $now,
                'closed_by' => $actorId,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'variance' => $variance,
                'gross' => $figures['gross'],
                'discounts' => $figures['discounts'],
                'tax' => $figures['tax'],
                'net' => $figures['net'],
                'sales_count' => $figures['salesCount'],
                'refunds_count' => $figures['refundsCount'],
                'totals_by_method' => $this->json($figures['byMethod']),
                'updated_at' => $now,
            ]);

            $after = $connection->table('cash_shifts')->where('id', $shiftId)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'cash_shift', $shiftId, (array) $shift, $after === null ? null : (array) $after, strtoupper((string) $shift->branch_id));

            return new CloseCashShiftResult(
                $shiftId,
                ShiftStatus::Closed->value,
                $expected,
                $counted,
                $variance,
                $figures['gross'],
                $figures['discounts'],
                $figures['tax'],
                $figures['net'],
                $figures['salesCount'],
                $figures['refundsCount'],
                $figures['byMethod'],
                false,
            );
        });
    }

    /**
     * Gross, discounts, tax, net, the two counts and the per-method totals, from this shift's settled documents.
     *
     * @return array{gross: string, discounts: string, tax: string, net: string, salesCount: int, refundsCount: int, byMethod: array<string, string>}
     */
    private function figures(Connection $connection, string $shiftId): array
    {
        $documents = $connection->table('sales')
            ->where('shift_id', $shiftId)
            ->whereIn('status', SaleStatus::settled())
            ->get(['id', 'type', 'subtotal', 'discount_total', 'tax_total', 'total']);

        $sales = $documents->filter(static fn (object $d): bool => (string) $d->type === SaleType::Sale->value);
        $refunds = $documents->filter(static fn (object $d): bool => (string) $d->type === SaleType::Refund->value);

        $byMethod = [];

        foreach ($connection->table('sale_tenders')->whereIn('sale_id', $documents->pluck('id')->all())->get(['method_code', 'amount']) as $tender) {
            $code = (string) $tender->method_code;
            $byMethod[$code] = Money::add($byMethod[$code] ?? Money::ZERO, Money::of($tender->amount));
        }

        ksort($byMethod);

        return [
            'gross' => Money::sum($sales->pluck('subtotal')->all()),
            'discounts' => Money::sum($sales->pluck('discount_total')->all()),
            'tax' => Money::sum($sales->pluck('tax_total')->all()),
            'net' => Money::subtract(Money::sum($sales->pluck('total')->all()), Money::sum($refunds->pluck('total')->all())),
            'salesCount' => $sales->count(),
            'refundsCount' => $refunds->count(),
            'byMethod' => $byMethod,
        ];
    }

    /**
     * The result of a close that already happened, read off the row.
     */
    private function stored(object $shift, bool $replayed): CloseCashShiftResult
    {
        $byMethod = $shift->totals_by_method === null ? [] : json_decode((string) $shift->totals_by_method, true);

        return new CloseCashShiftResult(
            strtoupper((string) $shift->id),
            (string) $shift->status,
            Money::of($shift->expected_cash ?? 0),
            Money::of($shift->counted_cash ?? 0),
            Money::of($shift->variance ?? 0),
            Money::of($shift->gross ?? 0),
            Money::of($shift->discounts ?? 0),
            Money::of($shift->tax ?? 0),
            Money::of($shift->net ?? 0),
            (int) $shift->sales_count,
            (int) $shift->refunds_count,
            is_array($byMethod) ? array_map(static fn (mixed $v): string => (string) $v, $byMethod) : [],
            $replayed,
        );
    }

    /**
     * @param  array<string, string>  $byMethod
     */
    private function json(array $byMethod): string
    {
        try {
            return json_encode((object) $byMethod, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SalesRefused::validation('The totals by method could not be stored.');
        }
    }
}

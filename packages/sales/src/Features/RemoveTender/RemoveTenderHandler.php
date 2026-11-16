<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RemoveTender;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\SaleLocks;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;

/**
 * Remove a tender (T-030 AC-13; D-29; Ruling 7).
 *
 * **A plain delete, and only while the sale is `pending_payment`.** No ledger
 * entry was ever posted for any tender (credit is posted at completion, F-3), so
 * there is nothing to compensate and nothing to orphan. Removing the last tender
 * returns the sale to `draft`. On every other status the tender stays:
 * `tender_not_removable`, and after completion the only corrections are a void
 * and a refund. A tender that is not on the named sale is `not_found` through it.
 *
 * The sale is locked `FOR UPDATE` first, so a removal and a completion cannot
 * both read the same tenders.
 */
final class RemoveTenderHandler
{
    public const ACTION = 'sales_tender_removed';

    private const FIELDS = ['sale_id', 'tender_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(RemoveTender $command): RemoveTenderResult
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A tender is removed by an employee.');
        }

        $saleId = Identifier::fold($command->fields['sale_id'] ?? null, 'sale_id');
        $tenderId = Identifier::fold($command->fields['tender_id'] ?? null, 'tender_id');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $saleId, $tenderId, $actorId): RemoveTenderResult {
            $sale = SaleLocks::lockSale($connection, $saleId);

            $tender = $connection->table('sale_tenders')->where('id', $tenderId)->where('sale_id', $saleId)->first();

            if ($tender === null) {
                throw SalesRefused::notFound("Sale [{$saleId}] has no tender [{$tenderId}].");
            }

            if ((string) $sale->status !== SaleStatus::PendingPayment->value) {
                throw SalesRefused::because(SalesRefusal::TenderNotRemovable, "Sale [{$saleId}] is [{$sale->status}]: its tenders can no longer be removed.");
            }

            $connection->table('sale_tenders')->where('id', $tenderId)->delete();

            $status = SaleStatus::PendingPayment->value;

            if (! $connection->table('sale_tenders')->where('sale_id', $saleId)->exists()) {
                $status = SaleStatus::Draft->value;
            }

            // W-3: the header carries what is still tendered, exactly, under the sale lock.
            $paid = Money::sum($connection->table('sale_tenders')->where('sale_id', $saleId)->pluck('amount')->all());

            $connection->table('sales')->where('id', $saleId)->update([
                'status' => $status,
                'paid_total' => $paid,
                'balance_due' => Money::subtract(Money::of($sale->total), $paid),
                'updated_at' => $this->clock->timestamp(),
            ]);

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'sale_tender', $tenderId, (array) $tender, null, strtoupper((string) $sale->branch_id));

            return new RemoveTenderResult($tenderId, $status);
        });
    }
}

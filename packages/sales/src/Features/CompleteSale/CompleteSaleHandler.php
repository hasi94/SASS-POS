<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CompleteSale;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Contracts\Pricing\DiscountUsage;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\SaleLocks;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Sales\SaleType;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;
use Flexipos\Sales\Tenders\CreditPosting;
use Flexipos\Sales\Tenders\MethodType;

/**
 * Complete a sale (T-030 AC-14, AC-14A, AC-15, AC-48 to AC-51, AC-58; D-29, D-31,
 * D-37, D-42, D-65, D-66, D-68, D-74; Ruling 7).
 *
 * **One path for every sale**, a free one included (D-74): a `draft` with at least
 * one line and a total of `0.0000` completes with no tender, numbered in sequence,
 * with its discounts consumed and its one audit row. Nothing here branches on the
 * total being zero; an empty sum of tenders simply equals it.
 *
 * **In one transaction, in lock order.** The sale `FOR UPDATE` first (a status and
 * a balance read before it would be a stale read), then, once the sale is known
 * to be completable (a status the state machine allows, at least one line, tenders
 * that sum to the total exactly: refusals that write nothing and take no further
 * lock):
 *
 * 1. `DiscountUsage::consume()` once per **distinct** discount, in **ascending id
 *    order**, so two completions consuming the same two discounts cannot
 *    deadlock. `usage_limit_reached` refuses the whole completion (AC-41, AC-58:
 *    a discount deactivated since the quote is still consumed);
 * 2. one `credit_sale` posting per credit tender ({@see CreditPosting}), which
 *    takes the customer lock. The ledger's refusal, or a replayed posting, is
 *    `credit_refused` carrying its cause and rolls everything back;
 * 3. the number, **last** (`DocumentNumbers::next('sale', $branchId)`): the
 *    company-wide sequence row is the hottest lock in the shop and is held to the
 *    commit, so nothing is locked after it. A numbering refusal is
 *    `numbering_refused` carrying its cause;
 * 4. the status, the totals and the audit row.
 *
 * **A deadlock is retried, once.** The whole body runs as `transaction(...,
 * ATTEMPTS)`: the engine's deadlock error rolls it all back (the number, the
 * usage increments, the ledger rows and their audit rows, the status) and runs it
 * once more, and a second deadlock reaches the caller as the engine's own error.
 * It retries only when this is the outermost transaction; a caller that opens one
 * around it takes the retry onto itself. NOT VERIFIED on a real engine.
 * Nothing the closure computes is carried across attempts.
 *
 * No stock is checked or written and nothing is posted to a general ledger
 * (AC-15, D-1, D-6); `stock_applied` stays false.
 */
final class CompleteSaleHandler
{
    public const ACTION = 'sales_sale_completed';

    private const FIELDS = ['sale_id'];

    private const ATTEMPTS = 2;

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
        private readonly DiscountUsage $usage,
        private readonly CreditPosting $credit,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function handle(CompleteSale $command): CompleteSaleResult
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A sale is completed by an employee.');
        }

        $saleId = Identifier::fold($command->fields['sale_id'] ?? null, 'sale_id');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $saleId, $actorId): CompleteSaleResult {
            $sale = SaleLocks::lockSale($connection, $saleId);
            $status = (string) $sale->status;

            if ((string) $sale->type !== SaleType::Sale->value || ! in_array($status, [SaleStatus::Draft->value, SaleStatus::PendingPayment->value], true)) {
                throw SalesRefused::because(SalesRefusal::InvalidTransition, "Sale [{$saleId}] is [{$status}] and cannot be completed.");
            }

            $lines = $connection->table('sale_items')->where('sale_id', $saleId)->get(['discount_id']);

            if ($lines->isEmpty()) {
                throw SalesRefused::because(SalesRefusal::EmptySale, "Sale [{$saleId}] has no lines.");
            }

            $tenders = $connection->table('sale_tenders')->where('sale_id', $saleId)->get();

            $total = Money::of($sale->total);
            $paid = Money::sum($tenders->pluck('amount')->all());

            if (! Money::equals($paid, $total)) {
                throw SalesRefused::because(SalesRefusal::BalanceDue, 'The balance due is '.Money::subtract($total, $paid).'.');
            }

            $discounts = $lines->pluck('discount_id')->filter()->map(static fn (mixed $id): string => strtoupper((string) $id))->unique()->sort()->values()->all();

            foreach ($discounts as $discountId) {
                try {
                    $this->usage->consume($discountId);
                } catch (PricingRefused $refused) {
                    throw SalesRefused::fromPricing($refused);
                }
            }

            $creditTenders = $tenders->filter(static fn (object $tender): bool => (string) $tender->method_type === MethodType::Credit->value);

            foreach ($creditTenders as $tender) {
                $this->credit->creditSale($sale, $tender, $actorId);
            }

            try {
                $number = $this->numbers->next('sale', strtoupper((string) $sale->branch_id));
            } catch (DocumentNumbersRefused $refused) {
                throw SalesRefused::because(SalesRefusal::NumberingRefused, $refused->getMessage(), $refused->reason);
            }

            $change = Money::sum($tenders->pluck('change_given')->all());
            $now = $this->clock->timestamp();

            $connection->table('sales')->where('id', $saleId)->update([
                'status' => SaleStatus::Completed->value,
                'number' => $number,
                'completed_at' => $now,
                'paid_total' => $paid,
                'change_due' => $change,
                'balance_due' => Money::ZERO,
                'credit_checked' => $creditTenders->isNotEmpty(),
                'updated_at' => $now,
            ]);

            $after = $connection->table('sales')->where('id', $saleId)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'sale', $saleId, (array) $sale, $after === null ? null : (array) $after, strtoupper((string) $sale->branch_id));

            return new CompleteSaleResult($saleId, $number, $total, $paid, $change);
        }, self::ATTEMPTS);
    }
}

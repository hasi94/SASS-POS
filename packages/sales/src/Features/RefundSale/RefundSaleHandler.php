<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RefundSale;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Contracts\Pricing\LineQuote;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Approvals\ApprovalBinding;
use Flexipos\Sales\Approvals\ApprovalGate;
use Flexipos\Sales\Approvals\ApprovalType;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\LineWriter;
use Flexipos\Sales\Sales\PendingLine;
use Flexipos\Sales\Sales\SaleLocks;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Sales\SaleType;
use Flexipos\Sales\Support\BusinessClock;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;
use Flexipos\Sales\Tenders\CreditPosting;
use Flexipos\Sales\Tenders\MethodType;
use Flexipos\Sales\Tenders\TenderOutcome;
use Flexipos\Sales\Tenders\TenderRules;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use LogicException;

/**
 * Refund a sale, or give goodwill with no original sale (T-030 AC-25 to AC-31,
 * AC-12A, AC-12B, AC-24A, AC-27A, AC-47A, AC-47B, AC-48, AC-50, AC-53, AC-54, AC-57; D-17 to D-19, D-32,
 * D-38, D-43, D-47, D-55, D-58, D-63, D-64, D-66, D-71 to D-73, D-75 to D-77; Ruling 7).
 *
 * **A refund is its own document**, never a negative sale: a `sales` row of
 * `type = refund`, numbered from the `refund` sequence, with its own lines, which
 * carry unsigned quantities and money (D-43), and its own tenders, which are
 * **negative**. It completes in the one call, when the tenders sum to minus the
 * total. The document's header money is unsigned too (what was paid out), so
 * `paid_total` equals `total`.
 *
 * **A referenced refund** (`reference_sale_id`) refunds lines of a `completed` or
 * `partially_refunded` sale at the same branch (`not_refundable` otherwise) and
 * copies its customer and snapshots. Each line names the original line it refunds;
 * its unit snapshot is `UnitResolver::snapshotInherited()`, never the ladder
 * (AC-26), and it is quoted by the engine at the original sale's `completed_at`,
 * `business_date` and the original line's discount (D-38, F-13): the engine's
 * input instant is anchored, not its method, so a smaller quantity can land on a
 * different price-list step. **A line sold at an approved price override is not
 * re-quoted** (AC-27A, D-77): it is refunded at its stored `unit_price`, in its own
 * unit only (`inherited_unit_mismatch` otherwise, the stock unit included), through
 * `LineWriter::overridden()`, which taxes it as a sale at the original
 * `business_date`. No override approval is consumed and the product's current
 * `allow_price_override` is never read. **A goodwill refund** names no sale: each line names a
 * product, is snapshotted by the ordinary ladder and quoted at the refund's own
 * instant, and takes an optional, live customer.
 *
 * **The engine's refusals pass through** (AC-53, R-1; owner question Q-1, still
 * open): a discount that has since reached its limit, been deactivated or deleted,
 * a product with `allow_discount` off or deleted, is refused under the engine's own
 * reason and nothing is written. Ruling Q-1 the other way means changing this
 * slice's quote step, and the dataset rows in its test that name each reason.
 * **The sellability check (AC-47) is never made here** (AC-47A, AC-47B, D-73,
 * D-75): a returned item may no longer be on sale, whatever its original line.
 *
 * **A refund pays out, never gives change.** Each tender goes through
 * {@see TenderRules::decide()} with `$refund = true`: the method row is read under
 * a shared lock (so `allows_refund = false` is `unsupported_method_type`, AC-12A),
 * `offered` is the payout and the balance is what is still owed back, both
 * magnitudes (AC-12B, D-76: a refund tender gives no change, so an offer above what is owed is `exceeds_balance`). A credit tender posts a `refund` ledger entry keyed on the refund
 * tender's own id (F-4); a posting reported as replayed is refused (R-4, D-66).
 *
 * **The approval is the replay guard.** A retry after success finds its approval
 * used (`approval_already_used`). It is consumed with the **refund's** branch, the
 * refunding terminal's (AC-24A, D-72), which for a referenced refund is the
 * original's.
 *
 * **Lock order (R-5).** The refunding terminal's open shift (shared, by id,
 * re-checked); the original sale `FOR UPDATE` (every refund of a sale takes it, so
 * `refunded_quantity` is read fresh under it, on every attempt); the approval; the
 * payment methods (shared); the customer; `number_sequences` last. Quotes and unit
 * snapshots run before the transaction (Ruling 7, item 1); ids are generated inside
 * it, so a retried attempt reuses nothing from a rolled-back one.
 *
 * **A deadlock is retried, once**, exactly as `CompleteSaleHandler` does it: the
 * whole body runs as `transaction(..., ATTEMPTS)` and retries only as the outermost
 * transaction. NOT VERIFIED on a real engine.
 *
 * No stock or general-ledger effect and no usage released (AC-31, D-37).
 */
final class RefundSaleHandler
{
    public const ACTION = 'sales_sale_refunded';

    private const FIELDS = ['terminal_id', 'reference_sale_id', 'customer_id', 'lines', 'tenders', 'approval_id'];

    private const REFERENCED_LINE_FIELDS = ['refund_of_item_id', 'unit_id', 'quantity'];

    private const GOODWILL_LINE_FIELDS = ['product_id', 'unit_id', 'quantity'];

    private const TENDER_FIELDS = ['payment_method_id', 'offered', 'reference'];

    private const MAX_LINES = 200;

    private const MAX_TENDERS = 20;

    private const ATTEMPTS = 2;

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly BusinessClock $business,
        private readonly AuditTrail $audit,
        private readonly TerminalAuthority $terminals,
        private readonly EmployeeDirectory $employees,
        private readonly UnitResolver $units,
        private readonly LineWriter $lines,
        private readonly ApprovalGate $approvals,
        private readonly CreditPosting $credit,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function handle(RefundSale $command): RefundSaleResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A refund is made by an employee.');
        }

        $terminalId = Identifier::fold($fields['terminal_id'] ?? null, 'terminal_id');
        $referenceId = Identifier::foldNullable($fields['reference_sale_id'] ?? null, 'reference_sale_id');
        $customerId = Identifier::foldNullable($fields['customer_id'] ?? null, 'customer_id');
        $approvalId = Identifier::foldNullable($fields['approval_id'] ?? null, 'approval_id');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if ($referenceId !== null && $customerId !== null) {
            throw SalesRefused::validation('A refund of a sale takes the sale\'s customer; customer_id is for a goodwill refund.');
        }

        $lineInputs = $this->lineInputs($fields['lines'] ?? null, $referenceId !== null);
        $tenderInputs = $this->tenderInputs($fields['tenders'] ?? []);

        $branchId = $this->terminals->activeBranchOf($terminalId);

        if ($branchId === null) {
            throw SalesRefused::notFound("No active terminal has id [{$terminalId}].");
        }

        $branchId = strtoupper($branchId);

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $connection = $this->tenant->get();

        $terminal = Lookup::live($connection, 'terminals', $terminalId, 'terminal');
        $branch = Lookup::live($connection, 'branches', $branchId, 'branch');

        $shiftId = SaleLocks::openShiftIdOf($connection, $terminalId)
            ?? throw SalesRefused::because(SalesRefusal::NoOpenShift, "Terminal [{$terminalId}] has no open cash shift.");

        // Plain reads: they decide what to price and which locks to take. The locked rows decide the rest.
        $original = null;

        if ($referenceId !== null) {
            $original = Lookup::any($connection, 'sales', $referenceId, 'sale');
            self::assertRefundable($original, $branchId);
        }

        $customer = null;

        if ($customerId !== null) {
            $customer = Lookup::live($connection, 'customers', $customerId, 'customer');

            if ($customer->merged_into_id !== null) {
                throw SalesRefused::notFound("No customer has id [{$customerId}].");
            }
        }

        $pending = $this->price($connection, $lineInputs, $original, $customer, $branch, $branchId);

        $total = Money::sum(array_map(static fn (array $p): string => (string) $p['line']->columns['line_total'], $pending));

        if (! Money::fits($total)) {
            throw SalesRefused::outOfRange('The refund total would not fit DECIMAL(18,4).');
        }

        $businessDate = $this->business->businessDate((string) $branch->timezone);
        $currency = $original === null ? strtoupper((string) $branch->currency) : strtoupper((string) $original->currency);
        $locationId = strtoupper((string) $terminal->location_id);

        return $connection->transaction(function () use ($connection, $terminalId, $shiftId, $branchId, $locationId, $referenceId, $customerId, $approvalId, $actorId, $pending, $tenderInputs, $total, $businessDate, $currency): RefundSaleResult {
            SaleLocks::lockOpenShift($connection, $shiftId, $terminalId);

            $originalRow = null;
            $items = [];

            if ($referenceId !== null) {
                $originalRow = SaleLocks::lockSale($connection, $referenceId);
                self::assertRefundable($originalRow, $branchId);

                foreach ($connection->table('sale_items')->where('sale_id', $referenceId)->get() as $item) {
                    $items[strtoupper((string) $item->id)] = $item;
                }
            }

            $this->approvals->consume(
                ApprovalType::Refund,
                $branchId,
                $referenceId !== null ? ApprovalBinding::SALE : ApprovalBinding::GOODWILL_REFUND,
                $referenceId,
                null,
                $approvalId,
            );

            if ($referenceId !== null) {
                $this->assertWithinRefundable($items, $pending);
            }

            $refundCustomerId = $originalRow === null ? $customerId : ($originalRow->customer_id === null ? null : strtoupper((string) $originalRow->customer_id));

            $decided = $this->decideTenders($connection, $tenderInputs, $total, $refundCustomerId);

            $customer = null;

            if ($originalRow === null && $customerId !== null) {
                $customer = Lookup::live($connection, 'customers', $customerId, 'customer', true);

                if ($customer->merged_into_id !== null) {
                    throw SalesRefused::notFound("No customer has id [{$customerId}].");
                }
            }

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $connection->table('sales')->insert([
                'id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
                'terminal_id' => $terminalId,
                'branch_id' => $branchId,
                'location_id' => $locationId,
                'shift_id' => $shiftId,
                'employee_id' => $actorId,
                'customer_id' => $refundCustomerId,
                'customer_name' => $originalRow !== null ? $originalRow->customer_name : ($customer === null ? null : $customer->name),
                'customer_phone' => $originalRow !== null ? $originalRow->customer_phone : ($customer === null ? null : $customer->phone),
                'customer_tax_no' => $originalRow !== null ? $originalRow->customer_tax_no : ($customer === null ? null : $customer->tax_no),
                'type' => SaleType::Refund->value,
                'reference_sale_id' => $referenceId,
                'status' => SaleStatus::Completed->value,
                'business_date' => $businessDate,
                'business_date_source' => 'server',
                'currency' => $currency,
                'subtotal' => Money::ZERO,
                'discount_total' => Money::ZERO,
                'tax_total' => Money::ZERO,
                'service_charge' => Money::ZERO,
                'rounding' => Money::ZERO,
                'total' => Money::ZERO,
                'paid_total' => Money::ZERO,
                'change_due' => Money::ZERO,
                'balance_due' => Money::ZERO,
                'completed_at' => $now,
            ]);

            foreach ($pending as $index => $p) {
                $row = $this->lines->newRow((string) Str::ulid(), $id, $index + 1, $p['product'], $p['line'], null, null, null);
                $row['refund_of_item_id'] = $p['item'] === null ? null : strtoupper((string) $p['item']);

                $connection->table('sale_items')->insert($row);
            }

            $totals = $this->lines->refreshHeader($connection, (object) ['id' => $id, 'paid_total' => Money::ZERO], $now);

            if (! Money::equals($totals->total, $total)) {
                throw new LogicException("The refund's rolled-up total [{$totals->total}] differs from the quoted total [{$total}].");
            }

            $refund = $connection->table('sales')->where('id', $id)->first();
            $tenderIds = [];

            foreach ($decided as $tender) {
                $tenderId = (string) Str::ulid();
                $amount = Money::negate($tender['outcome']->amount);

                $connection->table('sale_tenders')->insert([
                    'id' => $tenderId,
                    'sale_id' => $id,
                    'payment_method_id' => strtoupper((string) $tender['method']->id),
                    'method_code' => $tender['method']->code,
                    'method_type' => $tender['method']->type,
                    'amount' => $amount,
                    'tendered' => null,
                    'change_given' => Money::ZERO,
                    'reference' => $tender['reference'],
                    'payment_status' => 'recorded',
                    'paid_at' => $now,
                ]);

                $tenderIds[] = $tenderId;

                if ((string) $tender['method']->type === MethodType::Credit->value) {
                    $this->credit->refund($refund, $tenderId, $amount, $actorId);
                }
            }

            $originalStatus = $originalRow === null ? null : $this->applyToOriginal($connection, $originalRow, $items, $pending, $now);

            try {
                $number = $this->numbers->next('refund', $branchId);
            } catch (DocumentNumbersRefused $refused) {
                throw SalesRefused::because(SalesRefusal::NumberingRefused, $refused->getMessage(), $refused->reason);
            }

            $connection->table('sales')->where('id', $id)->update([
                'number' => $number,
                'paid_total' => $totals->total,
                'change_due' => Money::ZERO,
                'balance_due' => Money::ZERO,
                'credit_checked' => array_filter($decided, static fn (array $t): bool => (string) $t['method']->type === MethodType::Credit->value) !== [],
                'approval_request_id' => $approvalId,
                'updated_at' => $now,
            ]);

            $after = $connection->table('sales')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'sale', $id, null, $after === null ? null : (array) $after, $branchId);

            return new RefundSaleResult($id, $number, $totals->total, $tenderIds, $originalStatus);
        }, self::ATTEMPTS);
    }

    /**
     * What the original sale must be for a refund to be made against it.
     *
     * @throws SalesRefused not_refundable, fully_refunded
     */
    private static function assertRefundable(object $original, string $branchId): void
    {
        $status = (string) $original->status;

        if ((string) $original->type === SaleType::Sale->value && $status === SaleStatus::Refunded->value) {
            throw SalesRefused::because(SalesRefusal::FullyRefunded, "Sale [{$original->id}] has already been refunded in full.");
        }

        if ((string) $original->type !== SaleType::Sale->value || ! in_array($status, [SaleStatus::Completed->value, SaleStatus::PartiallyRefunded->value], true)) {
            throw SalesRefused::because(SalesRefusal::NotRefundable, "Document [{$original->id}] is a {$original->type} in [{$status}] and cannot be refunded.");
        }

        if (strtoupper((string) $original->branch_id) !== $branchId) {
            throw SalesRefused::because(SalesRefusal::NotRefundable, "Sale [{$original->id}] was made at another branch and cannot be refunded here.");
        }
    }

    /**
     * Each refund line against what its original line has left, in base units.
     *
     * @param  array<string, object>  $items  the original's lines, read under its lock
     * @param  list<array{line: PendingLine, product: object, item: ?string, base: string}>  $pending
     *
     * @throws SalesRefused fully_refunded, over_refund
     */
    private function assertWithinRefundable(array $items, array $pending): void
    {
        $anyLeft = false;

        foreach ($items as $item) {
            if (Money::compare(Money::of($item->base_quantity), Money::of($item->refunded_quantity)) > 0) {
                $anyLeft = true;
            }
        }

        if (! $anyLeft) {
            throw SalesRefused::because(SalesRefusal::FullyRefunded, 'Every line of the sale has already been refunded in full.');
        }

        foreach ($pending as $p) {
            $item = $items[(string) $p['item']];
            $remaining = Money::subtract(Money::of($item->base_quantity), Money::of($item->refunded_quantity));

            if (Money::compare($p['base'], $remaining) > 0) {
                throw SalesRefused::because(SalesRefusal::OverRefund, "Line [{$item->id}] has {$remaining} left to refund, and {$p['base']} was asked for.");
            }
        }
    }

    /**
     * Decide every refund tender under the method's shared lock: negative, summing to minus the total.
     *
     * @param  list<array{method: string, offered: string, reference: ?string}>  $inputs
     * @return list<array{method: object, outcome: TenderOutcome, reference: ?string}>
     *
     * @throws SalesRefused
     */
    private function decideTenders(Connection $connection, array $inputs, string $total, ?string $customerId): array
    {
        $remaining = $total;
        $decided = [];

        foreach ($inputs as $input) {
            $method = Lookup::live($connection, 'payment_methods', $input['method'], 'payment method', Lookup::SHARED);
            $outcome = TenderRules::decide($method, $input['offered'], $input['reference'], $remaining, true);

            if ((string) $method->type === MethodType::Credit->value && $customerId === null) {
                throw SalesRefused::because(SalesRefusal::CustomerRequired, 'A credit refund needs a customer.');
            }

            $remaining = Money::subtract($remaining, $outcome->amount);
            $decided[] = ['method' => $method, 'outcome' => $outcome, 'reference' => $input['reference']];
        }

        if (! Money::isZero($remaining)) {
            throw SalesRefused::because(SalesRefusal::BalanceDue, "The refund tenders leave {$remaining} unpaid.");
        }

        return $decided;
    }

    /**
     * Move the original's lines by what was refunded, and the sale's status with them.
     *
     * @param  array<string, object>  $items
     * @param  list<array{line: PendingLine, product: object, item: ?string, base: string}>  $pending
     */
    private function applyToOriginal(Connection $connection, object $original, array $items, array $pending, string $now): string
    {
        $refunded = [];

        foreach ($items as $key => $item) {
            $refunded[$key] = Money::of($item->refunded_quantity);
        }

        foreach ($pending as $p) {
            $key = (string) $p['item'];
            $refunded[$key] = Money::add($refunded[$key], $p['base']);

            $connection->table('sale_items')->where('id', $key)->update(['refunded_quantity' => $refunded[$key]]);
        }

        $all = true;

        foreach ($items as $key => $item) {
            if (Money::compare($refunded[$key], Money::of($item->base_quantity)) < 0) {
                $all = false;
            }
        }

        $status = $all ? SaleStatus::Refunded->value : SaleStatus::PartiallyRefunded->value;

        $connection->table('sales')->where('id', strtoupper((string) $original->id))->update(['status' => $status, 'updated_at' => $now]);

        return $status;
    }

    /**
     * Quote every line before the transaction opens.
     *
     * @param  list<array{item: ?string, product: ?string, unit: string, quantity: string}>  $inputs
     * @return list<array{line: PendingLine, product: object, item: ?string, base: string}>
     *
     * @throws SalesRefused
     */
    private function price(Connection $connection, array $inputs, ?object $original, ?object $customer, object $branch, string $branchId): array
    {
        $items = [];

        if ($original !== null) {
            foreach ($connection->table('sale_items')->where('sale_id', strtoupper((string) $original->id))->get() as $item) {
                $items[strtoupper((string) $item->id)] = $item;
            }
        }

        $pending = [];
        $seen = [];

        foreach ($inputs as $input) {
            if ($original !== null) {
                $item = $items[(string) $input['item']] ?? throw SalesRefused::notFound("No line [{$input['item']}] is on sale [{$original->id}].");

                if (isset($seen[(string) $input['item']])) {
                    throw SalesRefused::validation("Line [{$input['item']}] is named twice; name each line once with its whole quantity.");
                }

                $seen[(string) $input['item']] = true;

                $productId = strtoupper((string) $item->product_id);
                $product = (object) [
                    'id' => $productId,
                    'name' => $item->product_name,
                    'sku' => $item->sku,
                    'category_id' => $item->category_id,
                    'brand_id' => $item->brand_id,
                ];

                // AC-27A, D-77: a line sold at an approved override is refunded at that price, in its own unit only.
                $overridden = $item->price_overridden_by !== null;

                if ($overridden && strtoupper((string) $item->unit_id) !== $input['unit']) {
                    throw SalesRefused::fromUnit(UnitResolutionRefused::inheritedUnitMismatch(
                        "Line [{$input['item']}] was sold in unit [".strtoupper((string) $item->unit_id)."] at an overridden price and is refunded in that unit, not [{$input['unit']}].",
                    ));
                }

                $snapshot = $this->inherited($productId, $item, $input['unit'], $input['quantity']);

                $customerRef = $original->customer_id === null ? null : strtoupper((string) $original->customer_id);

                $quote = new LineQuote(
                    $productId,
                    $snapshot,
                    BusinessClock::instantOf((string) $original->completed_at),
                    substr((string) $original->business_date, 0, 10),
                    strtoupper((string) $original->currency),
                    $customerRef,
                    $this->groupOf($connection, $customerRef),
                    strtoupper((string) $original->branch_id),
                    $overridden || $item->discount_id === null ? null : strtoupper((string) $item->discount_id),
                );

                $line = $overridden ? $this->lines->overridden($snapshot, $quote, Money::of($item->unit_price)) : $this->lines->quoteSnapshot($snapshot, $quote);
                $itemId = (string) $input['item'];
            } else {
                $product = $this->lines->product($connection, (string) $input['product']);
                $productId = strtoupper((string) $product->id);

                try {
                    $snapshot = $this->units->snapshot($productId, $input['unit'], $input['quantity']);
                } catch (UnitResolutionRefused $refused) {
                    throw SalesRefused::fromUnit($refused);
                }

                $customerRef = $customer === null ? null : strtoupper((string) $customer->id);

                $quote = new LineQuote(
                    $productId,
                    $snapshot,
                    $this->business->instant(),
                    $this->business->businessDate((string) $branch->timezone),
                    strtoupper((string) $branch->currency),
                    $customerRef,
                    $this->groupOf($connection, $customerRef),
                    $branchId,
                    null,
                );

                $line = $this->lines->quoteSnapshot($snapshot, $quote);
                $itemId = null;
            }

            $pending[] = ['line' => $line, 'product' => $product, 'item' => $itemId, 'base' => Money::of($snapshot->baseQuantity)];
        }

        return $pending;
    }

    /**
     * The snapshot for a line that refers to an original line: its own factor
     * (or 1 for the stock unit), never a re-walked ladder (AC-26).
     *
     * @throws SalesRefused inherited_unit_mismatch, unit_unresolvable
     */
    private function inherited(string $productId, object $item, string $unitId, string $quantity): UnitSnapshot
    {
        try {
            return $this->units->snapshotInherited($productId, strtoupper((string) $item->unit_id), self::factor($item->unit_factor), $unitId, $quantity);
        } catch (UnitResolutionRefused $refused) {
            throw SalesRefused::fromUnit($refused);
        }
    }

    /**
     * A stored factor as the resolver wants it. The SQLite stand-in hands a
     * `DECIMAL(18,6)` back as an int or a float, MySQL as a string (R-8).
     */
    private static function factor(mixed $stored): string
    {
        return is_string($stored) ? $stored : number_format((float) $stored, 6, '.', '');
    }

    private function groupOf(Connection $connection, ?string $customerId): ?string
    {
        if ($customerId === null) {
            return null;
        }

        $group = $connection->table('customers')->where('id', $customerId)->value('group_id');

        return $group === null ? null : strtoupper((string) $group);
    }

    /**
     * @return list<array{item: ?string, product: ?string, unit: string, quantity: string}>
     *
     * @throws SalesRefused validation
     */
    private function lineInputs(mixed $raw, bool $referenced): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === [] || count($raw) > self::MAX_LINES) {
            throw SalesRefused::validation('A refund takes between 1 and '.self::MAX_LINES.' lines.');
        }

        $inputs = [];

        foreach ($raw as $line) {
            if (! is_array($line)) {
                throw SalesRefused::validation('Each refund line must be an object.');
            }

            Fields::only($line, $referenced ? self::REFERENCED_LINE_FIELDS : self::GOODWILL_LINE_FIELDS);

            $inputs[] = [
                'item' => $referenced ? Identifier::fold($line['refund_of_item_id'] ?? null, 'refund_of_item_id') : null,
                'product' => $referenced ? null : Identifier::fold($line['product_id'] ?? null, 'product_id'),
                'unit' => Identifier::fold($line['unit_id'] ?? null, 'unit_id'),
                'quantity' => Money::of(Fields::positiveMoney($line, 'quantity')),
            ];
        }

        return $inputs;
    }

    /**
     * @return list<array{method: string, offered: string, reference: ?string}>
     *
     * @throws SalesRefused validation
     */
    private function tenderInputs(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > self::MAX_TENDERS) {
            throw SalesRefused::validation('A refund takes at most '.self::MAX_TENDERS.' tenders.');
        }

        $inputs = [];

        foreach ($raw as $tender) {
            if (! is_array($tender)) {
                throw SalesRefused::validation('Each refund tender must be an object.');
            }

            Fields::only($tender, self::TENDER_FIELDS);

            $inputs[] = [
                'method' => Identifier::fold($tender['payment_method_id'] ?? null, 'payment_method_id'),
                'offered' => Money::of(Fields::positiveMoney($tender, 'offered')),
                'reference' => Fields::optionalText($tender, 'reference', 100),
            ];
        }

        return $inputs;
    }
}

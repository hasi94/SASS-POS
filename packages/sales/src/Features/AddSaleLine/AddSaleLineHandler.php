<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\AddSaleLine;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Approvals\LineApprovals;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Sales\LineWriter;
use Flexipos\Sales\Sales\SaleLocks;
use Flexipos\Sales\Sales\SaleType;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Support\Str;

/**
 * Add a line to a sale (T-030 AC-2 to AC-10, AC-23a, AC-47, AC-52; Ruling 7).
 *
 * **Resolve before you lock.** Everything the engine and the resolver decide runs
 * on the autocommit connection before the transaction opens ({@see LineWriter});
 * the transaction's first statement then locks the sale `FOR UPDATE`, re-checks
 * that its lines may still change (AC-6, F-10), takes the approval a price
 * override or an approval-gated discount needs (lock 3), and only then writes the
 * line and the re-summed header. A line that is refused writes nothing.
 *
 * **The quantity is checked first** (AC-52): zero or less is `validation` before
 * any unit or price is resolved, because the resolver would accept it.
 *
 * `price_from_label`, `modifiers`, the cost columns and every `[SYNC]` field are
 * not inputs (AC-9, AC-10): they are refused as unknown fields, and the line is
 * written with their defaults.
 */
final class AddSaleLineHandler
{
    public const ACTION = 'sales_sale_line_added';

    private const FIELDS = ['sale_id', 'product_id', 'unit_id', 'quantity', 'discount_id', 'unit_price', 'approval_id', 'employee_id', 'notes'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
        private readonly LineWriter $lines,
        private readonly LineApprovals $approvals,
    ) {}

    public function handle(AddSaleLine $command): AddSaleLineResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A line is added by an employee.');
        }

        $saleId = Identifier::fold($fields['sale_id'] ?? null, 'sale_id');
        $productId = Identifier::fold($fields['product_id'] ?? null, 'product_id');
        $unitId = Identifier::fold($fields['unit_id'] ?? null, 'unit_id');
        $quantity = Money::of(Fields::positiveMoney($fields, 'quantity'));
        $discountId = Identifier::foldNullable($fields['discount_id'] ?? null, 'discount_id');
        $approvalId = Identifier::foldNullable($fields['approval_id'] ?? null, 'approval_id');
        $lineEmployee = Identifier::foldNullable($fields['employee_id'] ?? null, 'employee_id');
        $overrideInput = Fields::money($fields, 'unit_price');
        $override = $overrideInput === null ? null : Money::of($overrideInput);
        $notes = Fields::notes($fields, 'notes');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        if ($lineEmployee !== null && ! $this->employees->isLive($lineEmployee)) {
            throw SalesRefused::notFound("No employee has id [{$lineEmployee}].");
        }

        $connection = $this->tenant->get();

        $sale = Lookup::any($connection, 'sales', $saleId, 'sale');
        $product = $this->lines->product($connection, $productId);
        $this->lines->assertSellable($product);

        $pending = $this->lines->price($connection, $sale, $product, $unitId, $quantity, $discountId, $override);
        $discountNeedsApproval = $this->lines->discountNeedsApproval($connection, $pending->discountId);

        return $connection->transaction(function () use ($connection, $saleId, $product, $pending, $discountNeedsApproval, $approvalId, $lineEmployee, $notes, $actorId): AddSaleLineResult {
            $sale = SaleLocks::lockSale($connection, $saleId);

            if ((string) $sale->type !== SaleType::Sale->value) {
                throw SalesRefused::because(SalesRefusal::SaleNotEditable, "Document [{$saleId}] is not a sale.");
            }

            SaleLocks::assertLinesEditable($connection, $sale);

            $overriddenBy = null;

            if ($pending->overridePayload !== null) {
                $overriddenBy = $this->approvals->coverOverride($sale, $pending->overridePayload, $approvalId)->decidedBy;
            } elseif ($discountNeedsApproval && $pending->discountId !== null) {
                $this->approvals->coverDiscount($sale, $pending->discountId, $approvalId);
            }

            $lineNo = ((int) $connection->table('sale_items')->where('sale_id', $saleId)->max('line_no')) + 1;
            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $connection->table('sale_items')->insert($this->lines->newRow($id, $saleId, $lineNo, $product, $pending, $lineEmployee, $notes, $overriddenBy));

            $totals = $this->lines->refreshHeader($connection, $sale, $now);

            $row = $connection->table('sale_items')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $actorId, self::ACTION, 'sale_item', $id, null, $row === null ? null : (array) $row, strtoupper((string) $sale->branch_id));

            return new AddSaleLineResult($id, $lineNo, Money::of($row === null ? Money::ZERO : $row->line_total), $totals->total);
        });
    }
}

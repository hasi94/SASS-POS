<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\UpdateSaleLine;

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
use Illuminate\Database\Connection;

/**
 * Re-price a line (T-030 AC-4 to AC-8, AC-47, AC-52, AC-55; Ruling 7).
 *
 * **A field left out keeps what the line holds**: the effective line is the
 * stored one with the submitted fields laid over it, and that whole line is
 * priced again. A line that carries an override keeps its override price when
 * only the quantity changes, and `unit_price: null` is how the override is
 * dropped. A discount on a line that the caller then overrides is
 * `discount_with_override`, as it is on an add, so changing a discounted line to
 * an override says `discount_id: null` in the same call.
 *
 * **Approvals (AC-55, D-59).** An approval already consumed by this sale for
 * exactly the override or the discount the line now carries covers it again; a
 * different price, unit or discount needs a new approval.
 *
 * The line is read before the transaction to be priced and **read again under
 * the sale lock**: if the stored line moved in between (two sessions editing one
 * line) the update is refused `replay_conflict` rather than written over what
 * the price was computed from.
 */
final class UpdateSaleLineHandler
{
    public const ACTION = 'sales_sale_line_updated';

    private const FIELDS = ['sale_id', 'line_id', 'quantity', 'unit_id', 'discount_id', 'unit_price', 'approval_id'];

    private const REPRICED = ['unit_id', 'unit_code', 'quantity', 'unit_factor', 'base_quantity', 'list_price', 'unit_price', 'subtotal', 'discount_id', 'discount_percent', 'discount_amount', 'tax_group_id', 'tax_amount', 'tax_breakdown', 'line_total'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
        private readonly LineWriter $lines,
        private readonly LineApprovals $approvals,
    ) {}

    public function handle(UpdateSaleLine $command): UpdateSaleLineResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('A line is updated by an employee.');
        }

        $saleId = Identifier::fold($fields['sale_id'] ?? null, 'sale_id');
        $lineId = Identifier::fold($fields['line_id'] ?? null, 'line_id');
        $submittedQuantity = array_key_exists('quantity', $fields) ? Money::of(Fields::positiveMoney($fields, 'quantity')) : null;
        $submittedUnit = array_key_exists('unit_id', $fields) ? Identifier::fold($fields['unit_id'], 'unit_id') : null;
        $approvalId = Identifier::foldNullable($fields['approval_id'] ?? null, 'approval_id');
        $actorId = Identifier::fold($command->actorId, 'actor');

        if (! $this->employees->isLive($actorId)) {
            throw SalesRefused::notFound("No employee has id [{$actorId}].");
        }

        $connection = $this->tenant->get();

        $sale = Lookup::any($connection, 'sales', $saleId, 'sale');
        $line = $this->line($connection, $saleId, $lineId);

        $discountId = array_key_exists('discount_id', $fields)
            ? Identifier::foldNullable($fields['discount_id'], 'discount_id')
            : ($line->discount_id === null ? null : strtoupper((string) $line->discount_id));

        if (array_key_exists('unit_price', $fields)) {
            $input = Fields::money($fields, 'unit_price');
            $override = $input === null ? null : Money::of($input);
        } else {
            $override = $line->price_overridden_by === null ? null : Money::of($line->unit_price);
        }

        $product = $this->lines->product($connection, strtoupper((string) $line->product_id));
        $this->lines->assertSellable($product);

        $pending = $this->lines->price(
            $connection,
            $sale,
            $product,
            $submittedUnit ?? strtoupper((string) $line->unit_id),
            $submittedQuantity ?? Money::of($line->quantity),
            $discountId,
            $override,
        );
        $discountNeedsApproval = $this->lines->discountNeedsApproval($connection, $pending->discountId);

        return $connection->transaction(function () use ($connection, $saleId, $lineId, $line, $pending, $discountNeedsApproval, $approvalId, $actorId): UpdateSaleLineResult {
            $sale = SaleLocks::lockSale($connection, $saleId);

            if ((string) $sale->type !== SaleType::Sale->value) {
                throw SalesRefused::because(SalesRefusal::SaleNotEditable, "Document [{$saleId}] is not a sale.");
            }

            SaleLocks::assertLinesEditable($connection, $sale);

            $current = $this->line($connection, $saleId, $lineId);

            if (! $this->unchanged($line, $current)) {
                throw SalesRefused::because(SalesRefusal::ReplayConflict, "Line [{$lineId}] changed while it was being re-priced.");
            }

            $overriddenBy = null;

            if ($pending->overridePayload !== null) {
                $overriddenBy = $this->approvals->coverOverride($sale, $pending->overridePayload, $approvalId)->decidedBy;
            } elseif ($discountNeedsApproval && $pending->discountId !== null) {
                $this->approvals->coverDiscount($sale, $pending->discountId, $approvalId);
            }

            $connection->table('sale_items')->where('id', $lineId)->update(array_merge(
                array_intersect_key($pending->columns, array_flip(self::REPRICED)),
                ['price_overridden_by' => $overriddenBy],
            ));

            $totals = $this->lines->refreshHeader($connection, $sale, $this->clock->timestamp());

            $after = $connection->table('sale_items')->where('id', $lineId)->first();

            $this->audit->record(
                SalesAuditActions::MODULE,
                $actorId,
                self::ACTION,
                'sale_item',
                $lineId,
                (array) $current,
                $after === null ? null : (array) $after,
                strtoupper((string) $sale->branch_id),
            );

            return new UpdateSaleLineResult($lineId, Money::of($after === null ? Money::ZERO : $after->line_total), $totals->total);
        });
    }

    /**
     * A line of this sale, or `not_found`: a line on another sale is not found
     * through this one.
     */
    private function line(Connection $connection, string $saleId, string $lineId): object
    {
        $line = $connection->table('sale_items')->where('id', $lineId)->where('sale_id', $saleId)->first();

        if ($line === null) {
            throw SalesRefused::notFound("Sale [{$saleId}] has no line [{$lineId}].");
        }

        return $line;
    }

    /**
     * Whether the facts the price was computed from are still what the line
     * holds, compared by value (a decimal as the stand-in returns it is the same
     * number as the one the pre-read saw).
     */
    private function unchanged(object $before, object $after): bool
    {
        return strtoupper((string) $before->unit_id) === strtoupper((string) $after->unit_id)
            && Money::equals(Money::of($before->quantity), Money::of($after->quantity))
            && ($before->discount_id === null ? null : strtoupper((string) $before->discount_id)) === ($after->discount_id === null ? null : strtoupper((string) $after->discount_id))
            && Money::equals(Money::of($before->unit_price), Money::of($after->unit_price))
            && ($before->price_overridden_by === null) === ($after->price_overridden_by === null);
    }
}

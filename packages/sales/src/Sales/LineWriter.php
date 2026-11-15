<?php

declare(strict_types=1);

namespace Flexipos\Sales\Sales;

use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Pricing\LineQuote;
use Flexipos\Contracts\Pricing\PricedLine;
use Flexipos\Contracts\Pricing\PricingEngine;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\TaxContext;
use Flexipos\Contracts\Pricing\TaxQuote;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\BusinessClock;
use Flexipos\Sales\Support\Money;
use Illuminate\Database\Connection;
use JsonException;

/**
 * Pricing a sale line, and writing what the price decided (T-030 AC-2 to AC-5,
 * AC-7, AC-8, AC-47; R-10, R-11).
 *
 * **Sales does no tax, discount or factor arithmetic.** The unit quad comes from
 * `UnitResolver::snapshot()`, every price, discount and tax figure from
 * `PricingEngine::quoteLine()`, stored verbatim. The one thing computed here is
 * the price override's `round(unitPrice x quantity, 4)`, and that product is
 * {@see Money::product()}: tax on it is the engine's `computeTax()`, and the line
 * total is `tax.net + tax.total`, which holds for an inclusive and an exclusive
 * group alike, so this class never branches on inclusivity.
 *
 * **Everything priced here runs before the write transaction opens** (Ruling 7,
 * item 1, "resolve before you lock"): a sale's customer, branch, business date
 * and currency are fixed when it is created, so nothing a lock would protect is
 * read.
 *
 * A malformed id never reaches a DTO (R-10): every id arrives already folded by
 * `Identifier::fold`, so the contracts' `InvalidArgumentException` is a caller
 * bug here and not a refusal.
 */
final class LineWriter
{
    public function __construct(
        private readonly UnitResolver $units,
        private readonly PricingEngine $pricing,
        private readonly BusinessClock $business,
    ) {}

    /**
     * A live product, or the engine's own refusal for one that is not: a
     * soft-deleted product is `unknown_product` (AC-53), not `not_found`.
     *
     * @throws SalesRefused
     */
    public function product(Connection $connection, string $productId): object
    {
        $product = $connection->table('products')->where('id', $productId)->first();

        if ($product === null || $product->deleted_at !== null) {
            throw SalesRefused::fromPricing(PricingRefused::unknownProduct("No product has id [{$productId}]."));
        }

        return $product;
    }

    /**
     * AC-47, D-57: an inactive, unsellable or variant-parent product is not rung
     * up. Only `AddSaleLine` and `UpdateSaleLine` call this; a refund line never
     * does (AC-47A, D-73), because a returned item may no longer be on sale.
     *
     * @throws SalesRefused product_not_sellable
     */
    public function assertSellable(object $product): void
    {
        $fact = match (true) {
            ! (bool) $product->is_active => 'is not active',
            ! (bool) $product->is_sellable => 'is not sellable',
            (string) $product->type === 'variant_parent' => 'is a variant parent',
            default => null,
        };

        if ($fact !== null) {
            throw SalesRefused::because(SalesRefusal::ProductNotSellable, "Product [{$product->id}] {$fact}.");
        }
    }

    /**
     * Price one line: the unit snapshot, the engine's quote, and for an
     * override the tax on the overridden subtotal.
     *
     * @throws SalesRefused
     */
    public function price(Connection $connection, object $sale, object $product, string $unitId, string $quantity, ?string $discountId, ?string $overridePrice): PendingLine
    {
        $productId = strtoupper((string) $product->id);

        if ($overridePrice !== null) {
            if (! (bool) $product->allow_price_override) {
                throw SalesRefused::because(SalesRefusal::PriceOverrideNotAllowed, "Product [{$productId}] does not allow a price override.");
            }

            if ($discountId !== null) {
                throw SalesRefused::because(SalesRefusal::DiscountWithOverride, 'An overridden line carries no item discount.');
            }
        }

        try {
            $snapshot = $this->units->snapshot($productId, $unitId, $quantity);
        } catch (UnitResolutionRefused $refused) {
            $unconvertible = in_array($refused->reason, [UnitRefusal::CrossDimension, UnitRefusal::NoConversionPath, UnitRefusal::UnknownUnit], true);

            throw SalesRefused::fromUnit($refused, $unconvertible
                ? "Unit [{$unitId}] does not convert to the stock unit [".strtoupper((string) $product->stock_unit_id).'] of product ['.$productId.'].'
                : null);
        }

        if (Money::isZero($snapshot->quantity)) {
            throw SalesRefused::validation('The quantity rounds to zero in this unit.');
        }

        $businessDate = substr((string) $sale->business_date, 0, 10);
        $customerId = $sale->customer_id === null ? null : strtoupper((string) $sale->customer_id);
        $groupId = null;

        if ($customerId !== null) {
            $group = $connection->table('customers')->where('id', $customerId)->value('group_id');
            $groupId = $group === null ? null : strtoupper((string) $group);
        }

        try {
            $priced = $this->pricing->quoteLine(new LineQuote(
                $productId,
                $snapshot,
                $this->business->instant(),
                $businessDate,
                strtoupper((string) $sale->currency),
                $customerId,
                $groupId,
                strtoupper((string) $sale->branch_id),
                $overridePrice === null ? $discountId : null,
            ));

            if ($overridePrice === null) {
                return new PendingLine($this->quotedColumns($snapshot, $priced), $priced->discountId, null);
            }

            $subtotal = Money::product($overridePrice, $snapshot->quantity);

            if (! Money::fits($subtotal)) {
                throw SalesRefused::outOfRange('The overridden line subtotal would not fit DECIMAL(18,4).');
            }

            $tax = $this->pricing->computeTax(new TaxQuote($priced->taxGroupId, $subtotal, $snapshot->baseQuantity, $businessDate, TaxContext::Sale));
            $lineTotal = Money::add($tax->net, $tax->total);

            if (! Money::fits($lineTotal)) {
                throw SalesRefused::outOfRange('The overridden line total would not fit DECIMAL(18,4).');
            }

            return new PendingLine(
                array_merge($this->unitColumns($snapshot), [
                    'list_price' => $priced->listPrice,
                    'unit_price' => $overridePrice,
                    'subtotal' => $subtotal,
                    'discount_id' => null,
                    'discount_percent' => null,
                    'discount_amount' => Money::ZERO,
                    'tax_group_id' => $priced->taxGroupId,
                    'tax_amount' => $tax->total,
                    'tax_breakdown' => $this->json($tax->toArray()),
                    'line_total' => $lineTotal,
                ]),
                null,
                ['product_id' => $productId, 'unit_id' => strtoupper($snapshot->unitId), 'unit_price' => $overridePrice],
            );
        } catch (PricingRefused $refused) {
            throw SalesRefused::fromPricing($refused);
        }
    }

    /**
     * Quote a line whose unit snapshot the caller already holds (a refund line,
     * whose factor may be inherited, AC-26, AC-27): the engine's figures, stored
     * verbatim, with the engine's own refusal carried through (AC-53). No
     * sellability check, no override and no approval: a refund line is none of those.
     *
     * @throws SalesRefused
     */
    public function quoteSnapshot(UnitSnapshot $snapshot, LineQuote $quote): PendingLine
    {
        if (Money::isZero($snapshot->quantity)) {
            throw SalesRefused::validation('The quantity rounds to zero in this unit.');
        }

        try {
            $priced = $this->pricing->quoteLine($quote);
        } catch (PricingRefused $refused) {
            throw SalesRefused::fromPricing($refused);
        }

        return new PendingLine($this->quotedColumns($snapshot, $priced), $priced->discountId, null);
    }

    /**
     * Whether the discount a line carries needs a consumed approval (AC-23a,
     * D-37): `discounts.requires_approval`, read as a fact. `approval_threshold`
     * and the workflow are not evaluated.
     */
    public function discountNeedsApproval(Connection $connection, ?string $discountId): bool
    {
        if ($discountId === null) {
            return false;
        }

        return (bool) $connection->table('discounts')->where('id', $discountId)->whereNull('deleted_at')->value('requires_approval');
    }

    /**
     * The row to insert for a new line: the priced columns, the product
     * snapshots taken now (a later rename leaves the line as it was, AC-2), and
     * the fields the add owns.
     *
     * @return array<string, mixed>
     */
    public function newRow(string $id, string $saleId, int $lineNo, object $product, PendingLine $line, ?string $employeeId, ?string $notes, ?string $overriddenBy): array
    {
        return array_merge([
            'id' => $id,
            'sale_id' => $saleId,
            'line_no' => $lineNo,
            'product_id' => strtoupper((string) $product->id),
            'product_name' => $product->name,
            'sku' => $product->sku,
            'category_id' => $product->category_id === null ? null : strtoupper((string) $product->category_id),
            'brand_id' => $product->brand_id === null ? null : strtoupper((string) $product->brand_id),
            'price_from_label' => false,
            'unit_cost' => null,
            'line_cost' => null,
            'employee_id' => $employeeId,
            'modifiers' => null,
            'notes' => $notes,
            'refunded_quantity' => Money::ZERO,
            'refund_of_item_id' => null,
            'price_overridden_by' => $overriddenBy,
            'sort_order' => $lineNo,
        ], $line->columns);
    }

    /**
     * Re-sum the header from the lines and write it (AC-8): the four rolled-up
     * figures and `balance_due`, which for a sale still being built is what is
     * left after what has been paid. Returns the new header figures.
     */
    public function refreshHeader(Connection $connection, object $sale, string $now): SaleTotals
    {
        $saleId = strtoupper((string) $sale->id);
        $totals = SaleRollup::of($connection, $saleId);

        $connection->table('sales')->where('id', $saleId)->update(array_merge($totals->toColumns(), [
            'balance_due' => Money::subtract($totals->total, Money::of($sale->paid_total)),
            'updated_at' => $now,
        ]));

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    private function quotedColumns(UnitSnapshot $snapshot, PricedLine $priced): array
    {
        return array_merge($this->unitColumns($snapshot), [
            'list_price' => $priced->listPrice,
            'unit_price' => $priced->unitPrice,
            'subtotal' => $priced->subtotal,
            'discount_id' => $priced->discountId,
            'discount_percent' => null,
            'discount_amount' => $priced->discountAmount,
            'tax_group_id' => $priced->taxGroupId,
            'tax_amount' => $priced->tax->total,
            'tax_breakdown' => $this->json($priced->tax->toArray()),
            'line_total' => $priced->lineTotal,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function unitColumns(UnitSnapshot $snapshot): array
    {
        return [
            'unit_id' => strtoupper($snapshot->unitId),
            'unit_code' => $snapshot->unitCode,
            'quantity' => $snapshot->quantity,
            'unit_factor' => $snapshot->unitFactor,
            'base_quantity' => $snapshot->baseQuantity,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SalesRefused::validation('The tax breakdown could not be stored.');
        }
    }
}

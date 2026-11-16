<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\BillDiscount;
use Flexipos\Contracts\Pricing\BillQuote;
use Flexipos\Contracts\Pricing\LineQuote;
use Flexipos\Contracts\Pricing\PricedLine;
use Flexipos\Contracts\Pricing\PricedPurchaseLine;
use Flexipos\Contracts\Pricing\PricingEngine;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\PurchaseLinePricing;
use Flexipos\Contracts\Pricing\PurchaseLineQuote;
use Flexipos\Contracts\Pricing\TaxBreakdown;
use Flexipos\Contracts\Pricing\TaxQuote;
use Flexipos\Pricing\Support\TenantConnection;
use Illuminate\Database\Connection;
use JsonException;
use stdClass;

/**
 * {@see PricingEngine} over the tenant database (T-029 plan Ruling 8, D-29).
 *
 * **It reads rows and normalises them, and does nothing else.** It holds no
 * arithmetic, no ordering and no selection: it builds the pure engine's inputs
 * from what the database holds and hands them over, so the fixture-proven
 * classes are the only place a price, a tax or a tie is decided.
 *
 * **The adapter never orders (D-29).** There is no sort, no limit and no pick
 * over any candidate set: every price list that carries the product, every
 * assignment and item of those lists, every member of the tax group and every
 * rate of those taxes is passed through in whatever order the engine returns
 * it, and `PriceLadder` and `TaxCalculator` order them bytewise. A SQL order
 * would be a second source of truth that the TypeScript twin cannot see and
 * that differs by collation per engine. `QaPricingSourceRulesTest` fails the
 * build on any ordering or limiting call in this file.
 *
 * **The SQL predicates are id equality only.** Whether a list is active,
 * deleted, in its window or in the document currency, and whether a member tax
 * is active or deleted, is not asked here: those facts are passed on
 * (`LadderList::$isDeleted`, `TaxMember::$isDeleted`) and the pure engine skips
 * them, so a fixture proves the one filter. The single exception is a
 * soft-deleted discount: {@see DiscountRule} carries no deleted flag, so such a
 * row is simply not loaded and surfaces as `unknown_discount`. A row is read
 * with `first()` or `value()` only through its key (the product, the tax group,
 * the discount, the product's branch row).
 *
 * **Normalisation is the one transformation** (K-5): ids are folded to upper
 * case (the engine compares ids with `===`), decimals are handed over as
 * strings, a `DATETIME(3)` becomes the `YYYY-MM-DDTHH:MM:SS.mmmZ` instant the
 * engine's shape check expects, and integers are cast.
 *
 * **A malformed stored `applies_to` is refused, never widened (W-4, D-38).** SQL
 * `NULL` means "applies to everything". Any other value must be the D-32 shape,
 * a JSON object whose only keys are `products`, `categories` and `brands`, each a
 * list of strings; anything else (including a JSON `null`, a list, a bare string
 * and a non-string entry) is `invalid_amount` at load, because reading it as
 * three empty lists would turn a discount meant for one product into a
 * tenant-wide one. Shape only: no id is checked for existence (D-32).
 *
 * `computeTax` uses the tax group it is given. The purchasing caller chooses
 * between a product's two tax groups before it asks (K-3).
 *
 * `quotePurchaseLine` (T-034 D-53a) is the second interface this class
 * implements: it reads the tax group and its members with the same two keyed
 * reads, an inactive group computed normally, and hands them to the pure
 * {@see PurchaseLineCalculator}.
 */
final class DatabasePricingEngine implements PricingEngine, PurchaseLinePricing
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function quoteLine(LineQuote $quote): PricedLine
    {
        $db = $this->tenant->get();

        $product = $db->table('products')->where('id', $quote->productId)->first();

        if ($product === null || $product->deleted_at !== null) {
            throw PricingRefused::unknownProduct("No product has id [{$quote->productId}].");
        }

        $productId = (string) $product->id;
        $group = $this->taxGroup($db, $product->tax_group_id === null ? null : (string) $product->tax_group_id);

        $ladder = new LadderInput(
            $quote->unit->unitId,
            $quote->unit->unitFactor,
            $quote->unit->baseQuantity,
            $quote->at,
            $quote->currency,
            $quote->customerId,
            $quote->customerGroupId,
            $quote->branchId,
            $this->candidateLists($db, $productId),
            $this->branchSellingPrice($db, $productId, $quote->branchId),
            self::decimal($product->selling_price),
        );

        return LineCalculator::compute(new LineInput(
            $ladder,
            $quote->unit->quantity,
            new ProductFacts(
                self::id($product->id),
                $product->category_id === null ? null : self::id($product->category_id),
                $product->brand_id === null ? null : self::id($product->brand_id),
                (bool) $product->allow_discount,
            ),
            self::id($group->id),
            (bool) $group->is_inclusive,
            $this->members($db, (string) $group->id),
            $quote->documentDate,
            $quote->discountId,
            $quote->discountId === null ? [] : $this->discounts($db, $quote->discountId),
        ));
    }

    public function computeTax(TaxQuote $quote): TaxBreakdown
    {
        $db = $this->tenant->get();
        $group = $this->taxGroup($db, $quote->taxGroupId);

        return TaxCalculator::compute(
            $this->members($db, (string) $group->id),
            $quote->amount,
            $quote->baseQuantity,
            $quote->documentDate,
            $quote->context,
            (bool) $group->is_inclusive,
        );
    }

    public function quotePurchaseLine(PurchaseLineQuote $quote): PricedPurchaseLine
    {
        $db = $this->tenant->get();
        $group = $this->taxGroup($db, $quote->taxGroupId);

        return PurchaseLineCalculator::compute(
            $quote,
            $this->members($db, (string) $group->id),
            (bool) $group->is_inclusive,
        );
    }

    public function billDiscount(BillQuote $quote): BillDiscount
    {
        $rules = $this->discounts($this->tenant->get(), $quote->discountId);

        return DiscountCalculator::forBill($rules[0] ?? null, $quote->at, $quote->discountedSubtotals);
    }

    // ------------------------------------------------------------------ reads

    /**
     * A key lookup. A null or dangling id is `unknown_tax_group`; an inactive
     * group is computed normally (K-12).
     *
     * @throws PricingRefused
     */
    private function taxGroup(Connection $db, ?string $taxGroupId): stdClass
    {
        $group = $taxGroupId === null ? null : $db->table('tax_groups')->where('id', $taxGroupId)->first();

        if (! $group instanceof stdClass) {
            throw PricingRefused::unknownTaxGroup('No tax group resolves to the requested id.');
        }

        return $group;
    }

    /**
     * `product_branches.selling_price` for the line's branch: one row, keyed by
     * its primary key. Null when the line has no branch or the row holds no
     * override.
     */
    private function branchSellingPrice(Connection $db, string $productId, ?string $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        $price = $db->table('product_branches')->where('product_id', $productId)->where('branch_id', $branchId)->value('selling_price');

        return $price === null ? null : self::decimal($price);
    }

    /**
     * Every price list that carries an item for the product, however it is
     * flagged, with all its assignments and that product's items. The engine
     * decides which of them qualify and in what order.
     *
     * @return list<LadderList>
     */
    private function candidateLists(Connection $db, string $productId): array
    {
        $itemRows = $db->table('price_list_items')->where('product_id', $productId)->get();

        $items = [];
        $listIds = [];

        foreach ($itemRows as $row) {
            $listIds[] = (string) $row->price_list_id;
            $items[self::id($row->price_list_id)][] = new LadderItem(
                $row->unit_id === null ? null : self::id($row->unit_id),
                self::decimal($row->min_qty),
                self::decimal($row->price),
                $row->discount_percent === null ? null : self::decimal($row->discount_percent),
            );
        }

        if ($listIds === []) {
            return [];
        }

        $listIds = array_values(array_unique($listIds));

        $assignments = [];

        foreach ($db->table('price_list_assignments')->whereIn('price_list_id', $listIds)->get() as $row) {
            $assignments[self::id($row->price_list_id)][] = new LadderAssignment(
                ScopeType::from((string) $row->scope_type),
                self::id($row->scope_id),
                (int) $row->priority,
            );
        }

        $lists = [];

        foreach ($db->table('price_lists')->whereIn('id', $listIds)->get() as $row) {
            $key = self::id($row->id);

            $lists[] = new LadderList(
                $key,
                (string) $row->code,
                ListType::from((string) $row->type),
                (string) $row->currency,
                (int) $row->priority,
                self::instant($row->starts_at),
                self::instant($row->ends_at),
                (bool) $row->is_active,
                $row->deleted_at !== null,
                $assignments[$key] ?? [],
                $items[$key] ?? [],
            );
        }

        return $lists;
    }

    /**
     * Every member of the group, with its tax and all that tax's rates.
     *
     * @return list<TaxMember>
     *
     * @throws PricingRefused
     */
    private function members(Connection $db, string $taxGroupId): array
    {
        $links = $db->table('tax_group_taxes')->where('tax_group_id', $taxGroupId)->get();

        if ($links->isEmpty()) {
            return [];
        }

        $taxIds = [];

        foreach ($links as $link) {
            $taxIds[] = (string) $link->tax_id;
        }

        $taxIds = array_values(array_unique($taxIds));

        $taxes = [];

        foreach ($db->table('taxes')->whereIn('id', $taxIds)->get() as $row) {
            $taxes[self::id($row->id)] = $row;
        }

        $rates = [];

        foreach ($db->table('tax_rates')->whereIn('tax_id', $taxIds)->get() as $row) {
            $rates[self::id($row->tax_id)][] = new RateRow(
                self::decimal($row->rate),
                substr((string) $row->effective_from, 0, 10),
                $row->effective_to === null ? null : substr((string) $row->effective_to, 0, 10),
            );
        }

        $members = [];

        foreach ($links as $link) {
            $key = self::id($link->tax_id);
            $tax = $taxes[$key] ?? throw PricingRefused::unknownTaxGroup("Tax group [{$taxGroupId}] links tax [{$key}], which does not exist.");

            $members[] = new TaxMember(
                $key,
                (string) $tax->code,
                $tax->tax_code === null ? null : (string) $tax->tax_code,
                TaxType::from((string) $tax->type),
                (bool) $tax->is_compound,
                TaxApplies::from((string) $tax->applies_to),
                (bool) $tax->is_active,
                $tax->deleted_at !== null,
                (int) $link->sequence,
                $rates[$key] ?? [],
            );
        }

        return $members;
    }

    /**
     * The requested discount, or none when it does not exist or is soft-deleted
     * (K-6): the pure engine then refuses `unknown_discount`.
     *
     * @return list<DiscountRule>
     */
    private function discounts(Connection $db, string $discountId): array
    {
        $row = $db->table('discounts')->where('id', $discountId)->whereNull('deleted_at')->first();

        if ($row === null) {
            return [];
        }

        $appliesTo = self::appliesTo(self::id($row->id), $row->applies_to);

        return [new DiscountRule(
            self::id($row->id),
            DiscountType::from((string) $row->type),
            self::decimal($row->value),
            DiscountScope::from((string) $row->scope),
            (bool) $row->is_active,
            self::instant($row->starts_at),
            self::instant($row->ends_at),
            $row->usage_limit === null ? null : (int) $row->usage_limit,
            (int) $row->used_count,
            $row->min_amount === null ? null : self::decimal($row->min_amount),
            $row->max_discount === null ? null : self::decimal($row->max_discount),
            $appliesTo['products'],
            $appliesTo['categories'],
            $appliesTo['brands'],
        )];
    }

    // ---------------------------------------------------------- normalisation

    private static function id(mixed $value): string
    {
        return strtoupper((string) $value);
    }

    /**
     * The three id lists of a stored `applies_to`, folded to upper case.
     *
     * @return array{products: list<string>, categories: list<string>, brands: list<string>}
     *
     * @throws PricingRefused invalid_amount
     */
    private static function appliesTo(string $discountId, mixed $stored): array
    {
        $lists = ['products' => [], 'categories' => [], 'brands' => []];

        if ($stored === null) {
            return $lists;
        }

        $refuse = static fn (string $why): PricingRefused => PricingRefused::invalidAmount("Discount [{$discountId}] has a malformed applies_to: {$why}.");

        try {
            // As objects, so that a JSON object and a JSON list stay distinguishable.
            $decoded = json_decode((string) $stored, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $refuse('it is not valid JSON');
        }

        if (! $decoded instanceof stdClass) {
            throw $refuse('it is not a JSON object');
        }

        foreach (get_object_vars($decoded) as $key => $value) {
            $key = (string) $key;

            if (! array_key_exists($key, $lists)) {
                throw $refuse("the key [{$key}] is not one of products, categories, brands");
            }

            if (! is_array($value) || ! array_is_list($value)) {
                throw $refuse("[{$key}] is not a list");
            }

            foreach ($value as $id) {
                if (! is_string($id)) {
                    throw $refuse("[{$key}] holds an entry that is not a string");
                }

                $lists[$key][] = strtoupper($id);
            }
        }

        return $lists;
    }

    /**
     * A decimal column as the string the engine's shape check reads. MySQL
     * hands a string already; the SQLite stand-in hands an int or a float.
     */
    private static function decimal(mixed $value): string
    {
        return is_float($value) ? number_format($value, 4, '.', '') : (string) $value;
    }

    /**
     * `Y-m-d H:i:s.v` (a `DATETIME(3)`) to `YYYY-MM-DDTHH:MM:SS.mmmZ`. Anything
     * else is passed on untouched, so the engine's own instant check refuses it
     * by name.
     */
    private static function instant(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D', $text, $parts) !== 1) {
            return $text;
        }

        return $parts[1].'T'.$parts[2].'.'.substr(str_pad($parts[3] ?? '', 3, '0'), 0, 3).'Z';
    }
}

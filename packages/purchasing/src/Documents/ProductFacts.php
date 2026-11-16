<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Identifier;
use Illuminate\Database\Connection;

/**
 * What purchasing may know about a product, read from `products` and never
 * written (T-034 AC-23, D-38, D-39).
 *
 * **Eligible** iff the product is not soft-deleted, `is_active`, `is_purchasable`,
 * `track_stock`, and of type `simple`, `variant` or `composite`: the positive
 * list, so a variant parent, a bundle and the non-stock product type are all
 * `product_not_purchasable` without any of them being named here. A product that
 * does not exist, is inactive or is soft-deleted is `unknown_product`: the caller
 * is told it is not a product it may use, not which of the three it is.
 *
 * **One `whereIn` per batch**, however many lines ask (AC-94): a conversion of
 * fifty lines reads the products once, not fifty times.
 */
final class ProductFacts
{
    /** The product types a buyer may order, as a positive list (D-39). */
    private const PURCHASABLE_TYPES = ['simple', 'variant', 'composite'];

    /**
     * Each id's facts, keyed by the folded product id.
     *
     * @param  list<mixed>  $productIds  duplicates are read once
     * @return array<string, object{id: string, name: string, purchase_tax_group_id: ?string}>
     *
     * @throws PurchasingRefused validation (malformed id), unknown_product, product_not_purchasable
     */
    public function purchasable(Connection $connection, array $productIds): array
    {
        $folded = [];

        foreach ($productIds as $productId) {
            $folded[Identifier::fold($productId, 'product_id')] = true;
        }

        if ($folded === []) {
            return [];
        }

        $rows = [];

        foreach ($connection->table('products')
            ->whereIn('id', array_keys($folded))
            ->get(['id', 'name', 'type', 'is_active', 'is_purchasable', 'track_stock', 'purchase_tax_group_id', 'deleted_at']) as $row) {
            $rows[strtoupper((string) $row->id)] = $row;
        }

        $facts = [];

        foreach (array_keys($folded) as $id) {
            $row = $rows[$id] ?? null;

            if ($row === null || $row->deleted_at !== null || ! (bool) $row->is_active) {
                throw PurchasingRefused::because(PurchasingRefusal::UnknownProduct, "No active product has id [{$id}].");
            }

            if (! (bool) $row->is_purchasable || ! (bool) $row->track_stock || ! in_array((string) $row->type, self::PURCHASABLE_TYPES, true)) {
                throw PurchasingRefused::because(PurchasingRefusal::ProductNotPurchasable, "Product [{$id}] cannot be bought: it is not a stocked, purchasable product.");
            }

            $facts[$id] = (object) [
                'id' => $id,
                'name' => (string) $row->name,
                'purchase_tax_group_id' => $row->purchase_tax_group_id === null ? null : strtoupper((string) $row->purchase_tax_group_id),
            ];
        }

        return $facts;
    }

    /**
     * One product's facts.
     *
     * @return object{id: string, name: string, purchase_tax_group_id: ?string}
     *
     * @throws PurchasingRefused validation (malformed id), unknown_product, product_not_purchasable
     */
    public function one(Connection $connection, mixed $productId): object
    {
        $facts = $this->purchasable($connection, [$productId]);

        return $facts[Identifier::fold($productId, 'product_id')];
    }
}

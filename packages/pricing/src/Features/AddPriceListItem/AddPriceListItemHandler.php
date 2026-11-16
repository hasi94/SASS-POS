<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\AddPriceListItem;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Add a price to a price list (T-029 AC-13).
 *
 * `unit_id`, when given, must be a unit the product's unit ladder can reach,
 * which is asked of the published `UnitResolver` and never re-derived here; a
 * null `unit_id` means "priced per stock unit, derive by the factor". `min_qty`
 * defaults to 1 and must be positive; `price` is required; `discount_percent`
 * is 0 to 100.
 *
 * A second row for the same list, product, unit and `min_qty` is refused,
 * naming the existing row. `min_qty` is compared as a number, so `1` and
 * `1.0000` are the same break: this is the write-time twin of the ladder's own
 * duplicate refusal (D-28).
 */
final class AddPriceListItemHandler
{
    public const ACTION = 'pricing_price_list_item_added';

    public const FIELDS = ['price_list_id', 'product_id', 'unit_id', 'min_qty', 'price', 'discount_percent'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
        private readonly UnitResolver $units,
    ) {}

    public function handle(AddPriceListItem $command): AddPriceListItemResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $unitId = Identifier::foldNullable($fields['unit_id'] ?? null, 'unit_id');
        $minQty = (string) Fields::money($fields, 'min_qty', '1');
        $price = Fields::money($fields, 'price');
        $discountPercent = Fields::rate($fields, 'discount_percent');

        if (BigDecimal::of($minQty)->isZero()) {
            throw PricingAdminRefused::validation('The min_qty must be greater than 0.');
        }

        if ($price === null) {
            throw PricingAdminRefused::validation('The price is required.');
        }

        if ($discountPercent !== null && BigDecimal::of($discountPercent)->compareTo(100) > 0) {
            throw PricingAdminRefused::validation('The discount_percent must be between 0 and 100.');
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $fields, $unitId, $minQty, $price, $discountPercent): AddPriceListItemResult {
            $list = Lookup::live($connection, 'price_lists', $fields['price_list_id'] ?? null, 'price list', lock: true);
            $product = Lookup::live($connection, 'products', $fields['product_id'] ?? null, 'product');

            $listId = (string) $list->id;
            $productId = (string) $product->id;

            if ($unitId !== null) {
                try {
                    $this->units->resolve($productId, $unitId);
                } catch (UnitResolutionRefused $refused) {
                    throw PricingAdminRefused::unresolvableUnit("Unit [{$unitId}] is not reachable for product [{$productId}]: {$refused->getMessage()}");
                }
            }

            $siblings = $connection->table('price_list_items')->where('price_list_id', $listId)->where('product_id', $productId);

            if ($unitId === null) {
                $siblings->whereNull('unit_id');
            } else {
                $siblings->where('unit_id', $unitId);
            }

            foreach ($siblings->get(['id', 'min_qty']) as $existing) {
                if (Fields::sameNumber((string) $existing->min_qty, $minQty)) {
                    throw PricingAdminRefused::duplicate("Item [{$existing->id}] already prices this product and unit at min_qty {$minQty}.");
                }
            }

            $id = (string) Str::ulid();

            try {
                $connection->table('price_list_items')->insert([
                    'id' => $id,
                    'price_list_id' => $listId,
                    'product_id' => $productId,
                    'unit_id' => $unitId,
                    'min_qty' => $minQty,
                    'price' => $price,
                    'discount_percent' => $discountPercent,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate('An item already prices this product and unit at this min_qty.');
            }

            $row = $connection->table('price_list_items')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'price_list_item', $id, null, $row === null ? null : (array) $row);

            return new AddPriceListItemResult($id);
        });
    }
}

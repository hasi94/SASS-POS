<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdatePriceListItem;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;

/**
 * Change an item's `price` or `discount_percent` (T-029 AC-13). The list,
 * product, unit and `min_qty` are the item's identity and are not update fields:
 * to move a break, remove the item and add another.
 */
final class UpdatePriceListItemHandler
{
    public const ACTION = 'pricing_price_list_item_updated';

    public const FIELDS = ['price', 'discount_percent'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdatePriceListItem $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($fields === []) {
            throw PricingAdminRefused::validation('An update must name at least one field.');
        }

        $id = Identifier::fold($command->priceListItemId, 'priceListItemId');

        $changes = [];

        if (Fields::has($fields, 'price')) {
            $price = Fields::money($fields, 'price');

            if ($price === null) {
                throw PricingAdminRefused::validation('The price may not be null.');
            }

            $changes['price'] = $price;
        }

        if (Fields::has($fields, 'discount_percent')) {
            $percent = Fields::rate($fields, 'discount_percent');

            if ($percent !== null && BigDecimal::of($percent)->compareTo(100) > 0) {
                throw PricingAdminRefused::validation('The discount_percent must be between 0 and 100.');
            }

            $changes['discount_percent'] = $percent;
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id, $changes): void {
            $before = Lookup::any($connection, 'price_list_items', $id, 'price list item', lock: true);

            $connection->table('price_list_items')->where('id', $id)->update($changes);

            $after = $connection->table('price_list_items')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'price_list_item', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}

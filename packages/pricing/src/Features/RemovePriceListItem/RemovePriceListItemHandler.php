<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\RemovePriceListItem;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TenantConnection;

/**
 * Remove a price list item (T-029 AC-13). A hard delete: the table carries no
 * `deleted_at`, and no document references an item (a document snapshots the
 * price it was given).
 */
final class RemovePriceListItemHandler
{
    public const ACTION = 'pricing_price_list_item_removed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(RemovePriceListItem $command): void
    {
        $id = Identifier::fold($command->priceListItemId, 'priceListItemId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id): void {
            $before = Lookup::any($connection, 'price_list_items', $id, 'price list item', lock: true);

            $connection->table('price_list_items')->where('id', $id)->delete();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'price_list_item', $id, (array) $before, null);
        });
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DeleteModifierGroup;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Soft-delete a modifier group (T-027 AC-36a, plan Ruling 9).
 *
 * Refused while attached to a live product (`product_modifier_groups`,
 * joined to `products`); a soft-deleted product does not block.
 */
final class DeleteModifierGroupHandler
{
    public const ACTION = 'catalog_modifier_group_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DeleteModifierGroup $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $groupId = Identifier::fold($command->groupId, 'groupId');

            $before = Lookup::live($connection, 'modifier_groups', $groupId, 'modifier_group', lock: true);

            $attachedToLiveProduct = $connection->table('product_modifier_groups')
                ->join('products', 'products.id', '=', 'product_modifier_groups.product_id')
                ->where('product_modifier_groups.group_id', $groupId)
                ->whereNull('products.deleted_at')
                ->exists();

            if ($attachedToLiveProduct) {
                throw CatalogRefused::inUse("Modifier group [{$groupId}] is still attached to a live product.");
            }

            $connection->table('modifier_groups')->where('id', $groupId)->update([
                'deleted_at' => $this->clock->timestamp(),
                'updated_at' => $this->clock->timestamp(),
            ]);

            $after = $connection->table('modifier_groups')->where('id', $groupId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'modifier_group',
                $groupId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}

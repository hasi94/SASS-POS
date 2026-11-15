<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DetachModifierGroup;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;

final class DetachModifierGroupHandler
{
    public const ACTION = 'catalog_modifier_group_detached';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DetachModifierGroup $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $productId = Identifier::fold($command->productId, 'productId');
            $groupId = Identifier::fold($command->groupId, 'groupId');

            $before = $connection->table('product_modifier_groups')
                ->where('product_id', $productId)
                ->where('group_id', $groupId)
                ->lockForUpdate()
                ->first();

            if ($before === null) {
                throw CatalogRefused::notFound("Product [{$productId}] is not attached to modifier group [{$groupId}].");
            }

            $connection->table('product_modifier_groups')->where('product_id', $productId)->where('group_id', $groupId)->delete();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_modifier_group',
                "{$productId}:{$groupId}",
                (array) $before,
                null,
            );
        });
    }
}

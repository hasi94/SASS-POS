<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\AttachModifierGroup;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Attach a modifier group to a product (T-027 AC-39, plan Ruling 9). Both
 * sides must be live, and a duplicate `(product_id, group_id)` pair is
 * refused rather than written twice.
 */
final class AttachModifierGroupHandler
{
    public const ACTION = 'catalog_modifier_group_attached';

    public const FIELDS = ['product_id', 'group_id', 'sort_order'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(AttachModifierGroup $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $productId = Identifier::fold($fields['product_id'] ?? null, 'product_id');
        $groupId = Identifier::fold($fields['group_id'] ?? null, 'group_id');
        $sortOrder = Fields::integer($fields, 'sort_order', 0);

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $productId, $groupId, $sortOrder): void {
            Lookup::live($connection, 'products', $productId, 'product_id');
            Lookup::live($connection, 'modifier_groups', $groupId, 'group_id');

            if ($connection->table('product_modifier_groups')->where('product_id', $productId)->where('group_id', $groupId)->exists()) {
                throw CatalogRefused::duplicate("Product [{$productId}] is already attached to modifier group [{$groupId}].");
            }

            try {
                $connection->table('product_modifier_groups')->insert([
                    'product_id' => $productId,
                    'group_id' => $groupId,
                    'sort_order' => $sortOrder,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate("Product [{$productId}] is already attached to modifier group [{$groupId}].");
            }

            $row = $connection->table('product_modifier_groups')->where('product_id', $productId)->where('group_id', $groupId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_modifier_group',
                "{$productId}:{$groupId}",
                null,
                $row === null ? null : (array) $row,
            );
        });
    }
}

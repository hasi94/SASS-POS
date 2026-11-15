<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateModifier;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\Money;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Create a modifier within a group (T-027 AC-37, AC-38, plan Ruling 9).
 *
 * **No `unit_factor` here** (AC-40a, D-19): unlike `product_components`,
 * this table does not snapshot a factor, so `linked_unit_id` takes the same
 * dimension-only check `CreateProduct`'s purchase/sales units take
 * (F-3/D-14) - a direct read of `units.dimension`, never
 * `UnitResolver::snapshot()`.
 */
final class CreateModifierHandler
{
    public const ACTION = 'catalog_modifier_created';

    public const FIELDS = [
        'group_id', 'name', 'price_delta', 'cost_delta',
        'linked_product_id', 'linked_quantity', 'linked_unit_id',
        'kitchen_note', 'sort_order', 'is_active',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateModifier $command): CreateModifierResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $groupId = Identifier::fold($fields['group_id'] ?? null, 'group_id');
        $name = Fields::requiredText($fields, 'name', 150);
        $priceDelta = Money::amount($fields, 'price_delta', true, '0.0000');
        $costDelta = Money::amount($fields, 'cost_delta', true, '0.0000');
        $kitchenNote = Fields::optionalText($fields, 'kitchen_note', 255);
        $sortOrder = Fields::integer($fields, 'sort_order', 0);
        $isActive = Fields::flag($fields, 'is_active', true);

        $hasLinkedProduct = Fields::has($fields, 'linked_product_id') && $fields['linked_product_id'] !== null;
        $hasLinkedQuantity = Fields::has($fields, 'linked_quantity') && $fields['linked_quantity'] !== null;
        $hasLinkedUnit = Fields::has($fields, 'linked_unit_id') && $fields['linked_unit_id'] !== null;

        if (! ($hasLinkedProduct === $hasLinkedQuantity && $hasLinkedQuantity === $hasLinkedUnit)) {
            throw CatalogRefused::validation('linked_product_id, linked_quantity and linked_unit_id must all be present, or none of them.');
        }

        $linkedProductId = $hasLinkedProduct ? Identifier::fold($fields['linked_product_id'], 'linked_product_id') : null;
        $linkedUnitId = $hasLinkedUnit ? Identifier::fold($fields['linked_unit_id'], 'linked_unit_id') : null;
        $linkedQuantity = null;

        if ($hasLinkedQuantity) {
            $linkedQuantity = Fields::decimal($fields, 'linked_quantity', 4);

            if ($linkedQuantity === null || (float) $linkedQuantity <= 0.0) {
                throw CatalogRefused::validation('The linked_quantity must be greater than 0.');
            }
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $groupId, $name, $priceDelta, $costDelta, $kitchenNote, $sortOrder, $isActive, $linkedProductId, $linkedQuantity, $linkedUnitId): CreateModifierResult {
            // W-1: locked, because two concurrent creates must not both read
            // these referenced rows as live before either commits.
            Lookup::live($connection, 'modifier_groups', $groupId, 'group_id', lock: true);

            if ($linkedProductId !== null) {
                $linkedProduct = Lookup::live($connection, 'products', $linkedProductId, 'linked_product_id', lock: true);

                self::checkUnitDimension($connection, (string) $linkedUnitId, (string) $linkedProduct->stock_unit_id);
            }

            $modifierId = (string) Str::ulid();

            $connection->table('modifiers')->insert([
                'id' => $modifierId,
                'group_id' => $groupId,
                'name' => $name,
                'price_delta' => $priceDelta,
                'cost_delta' => $costDelta,
                'linked_product_id' => $linkedProductId,
                'linked_quantity' => $linkedQuantity,
                'linked_unit_id' => $linkedUnitId,
                'kitchen_note' => $kitchenNote,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ]);

            $row = $connection->table('modifiers')->where('id', $modifierId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'modifier',
                $modifierId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateModifierResult($modifierId);
        });
    }

    /**
     * @throws CatalogRefused notFound, dimensionMismatch
     */
    private static function checkUnitDimension(Connection $connection, string $unitId, string $stockUnitId): void
    {
        $unit = $connection->table('units')->where('id', $unitId)->first();

        if ($unit === null) {
            throw CatalogRefused::notFound("No unit has id [{$unitId}] (linked_unit_id).");
        }

        $stockUnit = $connection->table('units')->where('id', $stockUnitId)->first();

        if ((string) $unit->dimension !== (string) ($stockUnit === null ? null : $stockUnit->dimension)) {
            throw CatalogRefused::dimensionMismatch("The linked_unit_id [{$unitId}] ({$unit->dimension}) does not share the linked product's stock unit dimension.");
        }
    }
}

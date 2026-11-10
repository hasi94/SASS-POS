<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\AddProductComponent;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Products\ComponentGraph;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Illuminate\Support\Str;

/**
 * Add a component line to a product's recipe (T-027 AC-34, AC-34a, plan
 * Ruling 8).
 *
 * **Reaches `Flexipos\Contracts\Catalog\UnitResolver`, never
 * `DatabaseUnitResolver`** (`QaCatalogSourceRulesTest`'s "one ladder, one
 * caller"): `unit_id`, `quantity`, `unit_factor` and `base_quantity` all
 * come from {@see UnitResolver::snapshot()} and are **frozen on the row**
 * (D-62) - a later change to a conversion factor never silently re-scales an
 * already-written recipe line. Changing the recipe means remove plus
 * re-add, which re-resolves.
 *
 * **AC-34a.** The transitive-cycle refusal is {@see ComponentGraph}'s job,
 * run over every existing `(parent_product_id, component_product_id)` pair
 * before the new edge is written.
 */
final class AddProductComponentHandler
{
    public const ACTION = 'catalog_product_component_added';

    public const FIELDS = ['parent_product_id', 'component_product_id', 'unit_id', 'quantity', 'wastage_percent', 'is_optional', 'sort_order'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly UnitResolver $resolver,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(AddProductComponent $command): AddProductComponentResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $parentId = Identifier::fold($fields['parent_product_id'] ?? null, 'parent_product_id');
        $componentId = Identifier::fold($fields['component_product_id'] ?? null, 'component_product_id');
        $unitId = Identifier::fold($fields['unit_id'] ?? null, 'unit_id');
        $quantity = self::positiveQuantity($fields);
        $wastagePercent = self::wastagePercent($fields);
        $isOptional = Fields::flag($fields, 'is_optional', false);
        $sortOrder = Fields::integer($fields, 'sort_order', 0);

        if ($parentId === $componentId) {
            throw CatalogRefused::validation("A product [{$parentId}] cannot be its own component.");
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $parentId, $componentId, $unitId, $quantity, $wastagePercent, $isOptional, $sortOrder): AddProductComponentResult {
            // W-1: locked, because two concurrent adds must not both read
            // these referenced rows as live before either commits.
            Lookup::live($connection, 'products', $parentId, 'parent_product_id', lock: true);
            Lookup::live($connection, 'products', $componentId, 'component_product_id', lock: true);

            if ($connection->table('product_components')->where('parent_product_id', $parentId)->where('component_product_id', $componentId)->exists()) {
                throw CatalogRefused::duplicate("Product [{$parentId}] already has component [{$componentId}].");
            }

            // W-1: the whole edge set `ComponentGraph` reads is locked, not
            // just the two product rows above - a cycle can be closed by two
            // edges with no products in common (e.g. A->B and C->D, given
            // existing B->C and D->A), so locking only the rows one edge
            // touches would not stop two concurrent adds from jointly
            // closing a cycle neither alone would have been refused for.
            // This race can't be demonstrated on the SQLite stand-in, which
            // serializes writes at the connection/process level - there is
            // no new test here for that reason; see W-1 in the round-1
            // review (docs/reviews/T-027-catalog.md).
            $edges = $connection->table('product_components')->lockForUpdate()->get(['parent_product_id', 'component_product_id'])
                ->map(static fn (object $row): array => ['parent' => (string) $row->parent_product_id, 'component' => (string) $row->component_product_id])
                ->all();

            if (ComponentGraph::wouldCreateCycle($edges, $parentId, $componentId)) {
                throw CatalogRefused::cycle("Adding component [{$componentId}] to product [{$parentId}] would close a cycle.");
            }

            $snapshot = self::snapshot($this->resolver, $componentId, $unitId, $quantity);

            // W-2: checked on the snapshot's own (already-rounded) quantity,
            // not the raw input - a tiny entered quantity, or a precision-0
            // line unit, can round down to zero and must still be refused.
            // Compared as a decimal string, never by multiplying, so this
            // stays clear of QaCatalogSourceRulesTest's factor-multiplication
            // scan.
            if (self::isZero($snapshot->quantity)) {
                throw CatalogRefused::validation("The quantity [{$quantity}] rounds to zero at unit [{$unitId}]'s precision.");
            }

            $componentRowId = (string) Str::ulid();

            $connection->table('product_components')->insert([
                'id' => $componentRowId,
                'parent_product_id' => $parentId,
                'component_product_id' => $componentId,
                'unit_id' => $snapshot->unitId,
                'quantity' => $snapshot->quantity,
                'unit_factor' => $snapshot->unitFactor,
                'base_quantity' => $snapshot->baseQuantity,
                'wastage_percent' => $wastagePercent,
                'is_optional' => $isOptional,
                'sort_order' => $sortOrder,
            ]);

            $row = $connection->table('product_components')->where('id', $componentRowId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_component',
                $componentRowId,
                null,
                $row === null ? null : (array) $row,
            );

            return new AddProductComponentResult($componentRowId);
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws CatalogRefused validation
     */
    private static function positiveQuantity(array $fields): int|string
    {
        $value = $fields['quantity'] ?? null;

        if (! is_int($value) && ! is_string($value)) {
            throw CatalogRefused::validation('The quantity is required and must be an int or a decimal string.');
        }

        if ((float) $value <= 0.0) {
            throw CatalogRefused::validation('The quantity must be greater than 0.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws CatalogRefused validation
     */
    private static function wastagePercent(array $fields): string
    {
        $value = Fields::decimal($fields, 'wastage_percent', 4, false, '0.0000');

        if ($value === null || (float) $value < 0.0 || (float) $value > 100.0) {
            throw CatalogRefused::validation('The wastage_percent must be 0 to 100.');
        }

        return $value;
    }

    /**
     * `$value` is {@see UnitSnapshot}'s `quantity`/`baseQuantity` shape:
     * a `DECIMAL(18,4)` string, optionally signed. True for `0.0000`,
     * `-0.0000` or any sign-and-zeros-only variant.
     */
    private static function isZero(string $value): bool
    {
        return preg_match('/^-?0\.0*$/', $value) === 1;
    }

    /**
     * @throws CatalogRefused dimensionMismatch, notFound, validation
     */
    private static function snapshot(UnitResolver $resolver, string $componentId, string $unitId, int|string $quantity): UnitSnapshot
    {
        try {
            return $resolver->snapshot($componentId, $unitId, $quantity);
        } catch (UnitResolutionRefused $refused) {
            if ($refused->reason === UnitRefusal::CrossDimension) {
                throw CatalogRefused::dimensionMismatch($refused->getMessage());
            }

            if ($refused->reason === UnitRefusal::UnknownProduct || $refused->reason === UnitRefusal::UnknownUnit) {
                throw CatalogRefused::notFound($refused->getMessage());
            }

            throw CatalogRefused::validation($refused->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateProductLocation;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Set a product's per-location stock settings (T-027 AC-32, plan Ruling 9).
 *
 * Every quantity is in the product's own stock unit and is **never
 * converted** - `docs/database.md` §6.3 is explicit that this table reads no
 * unit column at all.
 */
final class CreateProductLocationHandler
{
    public const ACTION = 'catalog_product_location_created';

    public const FIELDS = ['product_id', 'location_id', 'is_available', 'reorder_level', 'reorder_qty', 'max_level', 'bin_location'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateProductLocation $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $productId = Identifier::fold($fields['product_id'] ?? null, 'product_id');
        $locationId = Identifier::fold($fields['location_id'] ?? null, 'location_id');
        $isAvailable = Fields::flag($fields, 'is_available', true);
        $reorderLevel = self::nonNegative($fields, 'reorder_level');
        $reorderQty = self::nonNegative($fields, 'reorder_qty');
        $maxLevel = self::nonNegative($fields, 'max_level');
        $binLocation = Fields::optionalText($fields, 'bin_location', 40);

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $productId, $locationId, $isAvailable, $reorderLevel, $reorderQty, $maxLevel, $binLocation): void {
            Lookup::live($connection, 'products', $productId, 'product');
            Lookup::live($connection, 'locations', $locationId, 'location');

            if ($connection->table('product_locations')->where('product_id', $productId)->where('location_id', $locationId)->exists()) {
                throw CatalogRefused::duplicate("Product [{$productId}] already has settings at location [{$locationId}].");
            }

            $now = $this->clock->timestamp();

            try {
                $connection->table('product_locations')->insert([
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'is_available' => $isAvailable,
                    'reorder_level' => $reorderLevel,
                    'reorder_qty' => $reorderQty,
                    'max_level' => $maxLevel,
                    'bin_location' => $binLocation,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate("Product [{$productId}] already has settings at location [{$locationId}].");
            }

            $row = $connection->table('product_locations')->where('product_id', $productId)->where('location_id', $locationId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_location',
                "{$productId}:{$locationId}",
                null,
                $row === null ? null : (array) $row,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws CatalogRefused validation
     */
    private static function nonNegative(array $fields, string $key): string
    {
        $value = Fields::decimal($fields, $key, 4, false, '0.0000');

        if ($value === null || (float) $value < 0.0) {
            throw CatalogRefused::validation("The {$key} must be at least 0.");
        }

        return $value;
    }
}

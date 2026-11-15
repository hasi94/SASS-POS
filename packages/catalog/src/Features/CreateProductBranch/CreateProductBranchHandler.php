<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateProductBranch;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\Money;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Set a product's per-branch selling-price override (T-027 AC-33, plan
 * Ruling 9). `selling_price` null inherits `products.selling_price`.
 */
final class CreateProductBranchHandler
{
    public const ACTION = 'catalog_product_branch_created';

    public const FIELDS = ['product_id', 'branch_id', 'is_available', 'selling_price'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateProductBranch $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $productId = Identifier::fold($fields['product_id'] ?? null, 'product_id');
        $branchId = Identifier::fold($fields['branch_id'] ?? null, 'branch_id');
        $isAvailable = Fields::flag($fields, 'is_available', true);
        $sellingPrice = Money::amount($fields, 'selling_price', false, null);

        if ($sellingPrice !== null && (float) $sellingPrice < 0.0) {
            throw CatalogRefused::validation('The selling_price must be null or at least 0.');
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $productId, $branchId, $isAvailable, $sellingPrice): void {
            Lookup::live($connection, 'products', $productId, 'product');
            Lookup::any($connection, 'branches', $branchId, 'branch');

            if ($connection->table('product_branches')->where('product_id', $productId)->where('branch_id', $branchId)->exists()) {
                throw CatalogRefused::duplicate("Product [{$productId}] already has settings at branch [{$branchId}].");
            }

            $now = $this->clock->timestamp();

            try {
                $connection->table('product_branches')->insert([
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'is_available' => $isAvailable,
                    'selling_price' => $sellingPrice,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate("Product [{$productId}] already has settings at branch [{$branchId}].");
            }

            $row = $connection->table('product_branches')->where('product_id', $productId)->where('branch_id', $branchId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_branch',
                "{$productId}:{$branchId}",
                null,
                $row === null ? null : (array) $row,
            );
        });
    }
}

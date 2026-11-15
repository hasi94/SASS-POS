<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateBrand;

/**
 * Update a brand (T-027 AC-7, plan Ruling 10).
 *
 * Included so a typo in `code` or `name` can be fixed - both unique keys
 * hold their value forever across a soft delete, so delete and recreate
 * could never fix one.
 */
final class UpdateBrand
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $brandId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

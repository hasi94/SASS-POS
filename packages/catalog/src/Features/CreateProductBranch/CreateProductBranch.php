<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateProductBranch;

/**
 * Set a product's per-branch selling-price override (T-027 AC-33, plan
 * Ruling 9).
 */
final class CreateProductBranch
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

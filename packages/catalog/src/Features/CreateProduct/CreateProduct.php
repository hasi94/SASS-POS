<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateProduct;

/**
 * Create a product (T-027 AC-18 to AC-27, AC-27a, plan Ruling 8).
 */
final class CreateProduct
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

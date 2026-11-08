<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\AddProductComponent;

/**
 * Add a component line to a product's recipe (T-027 AC-34, AC-34a, plan
 * Ruling 8).
 */
final class AddProductComponent
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateProductLocation;

/**
 * Set a product's per-location stock settings (T-027 AC-32, plan Ruling 9).
 */
final class CreateProductLocation
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

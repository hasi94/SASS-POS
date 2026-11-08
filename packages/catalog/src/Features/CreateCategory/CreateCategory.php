<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateCategory;

/**
 * Create a product category (T-027 AC-1 to AC-3, AC-3a, plan Ruling 7).
 */
final class CreateCategory
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

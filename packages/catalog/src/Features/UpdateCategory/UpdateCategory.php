<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateCategory;

/**
 * Update a category's own fields, or move it under a new parent (T-027
 * AC-1 to AC-4, AC-3a, plan Ruling 7).
 */
final class UpdateCategory
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $categoryId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

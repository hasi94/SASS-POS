<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DeleteCategory;

/**
 * Soft-delete a category (T-027 AC-5, plan Ruling 7).
 */
final class DeleteCategory
{
    public function __construct(
        public readonly string $categoryId,
        public readonly ?string $actorId = null,
    ) {}
}

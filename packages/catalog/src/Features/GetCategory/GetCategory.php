<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\GetCategory;

/**
 * Read one category by id (T-027 plan Ruling 7). Returns a soft-deleted
 * row too, because its path must stay true for as long as any other row's
 * `path` can reference it (AC-3).
 */
final class GetCategory
{
    public function __construct(
        public readonly string $categoryId,
    ) {}
}

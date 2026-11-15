<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveProductComponent;

/**
 * Remove a component line from a product's recipe (T-027 AC-34, plan
 * Ruling 8). This is also how a recipe line is "changed": remove, then
 * re-add, which re-resolves its factor.
 */
final class RemoveProductComponent
{
    public function __construct(
        public readonly string $componentId,
        public readonly ?string $actorId = null,
    ) {}
}

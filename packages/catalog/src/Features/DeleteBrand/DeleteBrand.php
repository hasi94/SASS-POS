<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DeleteBrand;

/**
 * Soft-delete a brand (T-027 AC-8, plan Ruling 10).
 */
final class DeleteBrand
{
    public function __construct(
        public readonly string $brandId,
        public readonly ?string $actorId = null,
    ) {}
}

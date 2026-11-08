<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveProductBranch;

final class RemoveProductBranch
{
    public function __construct(
        public readonly string $productId,
        public readonly string $branchId,
        public readonly ?string $actorId = null,
    ) {}
}

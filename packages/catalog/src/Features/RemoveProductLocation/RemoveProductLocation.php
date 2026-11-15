<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveProductLocation;

final class RemoveProductLocation
{
    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly ?string $actorId = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DetachModifierGroup;

final class DetachModifierGroup
{
    public function __construct(
        public readonly string $productId,
        public readonly string $groupId,
        public readonly ?string $actorId = null,
    ) {}
}

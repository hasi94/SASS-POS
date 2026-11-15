<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateModifierGroup;

final class CreateModifierGroupResult
{
    public function __construct(
        public readonly string $groupId,
    ) {}
}

<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateModifier;

final class CreateModifierResult
{
    public function __construct(
        public readonly string $modifierId,
    ) {}
}

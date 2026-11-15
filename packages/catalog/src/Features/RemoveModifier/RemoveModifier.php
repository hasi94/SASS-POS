<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveModifier;

final class RemoveModifier
{
    public function __construct(
        public readonly string $modifierId,
        public readonly ?string $actorId = null,
    ) {}
}

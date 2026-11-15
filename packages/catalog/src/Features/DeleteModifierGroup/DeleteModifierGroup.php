<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DeleteModifierGroup;

/**
 * Soft-delete a modifier group (T-027 AC-36a, plan Ruling 9).
 */
final class DeleteModifierGroup
{
    public function __construct(
        public readonly string $groupId,
        public readonly ?string $actorId = null,
    ) {}
}

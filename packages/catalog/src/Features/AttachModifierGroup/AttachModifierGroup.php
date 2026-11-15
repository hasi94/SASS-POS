<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\AttachModifierGroup;

/**
 * Attach a modifier group to a product (T-027 AC-39, plan Ruling 9).
 */
final class AttachModifierGroup
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

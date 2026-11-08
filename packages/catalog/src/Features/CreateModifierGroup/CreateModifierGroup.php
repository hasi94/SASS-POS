<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateModifierGroup;

/**
 * Create a modifier group (T-027 AC-36, plan Ruling 9).
 */
final class CreateModifierGroup
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

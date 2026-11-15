<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateModifier;

/**
 * Create a modifier within a group (T-027 AC-37, AC-38, plan Ruling 9).
 */
final class CreateModifier
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

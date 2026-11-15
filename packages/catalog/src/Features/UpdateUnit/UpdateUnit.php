<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateUnit;

/**
 * Update a unit of measure (T-027 AC-11, plan Ruling 6).
 */
final class UpdateUnit
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $unitId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

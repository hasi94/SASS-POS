<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateUnitConversion;

/**
 * Create a named unit-to-unit conversion row (T-027 AC-13, AC-13a, AC-14,
 * plan Ruling 3, Ruling 9).
 */
final class CreateUnitConversion
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

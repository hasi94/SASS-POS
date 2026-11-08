<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveUnitConversion;

/**
 * Remove a unit-to-unit conversion row (T-027 AC-13, AC-14, plan Ruling 10:
 * a factor change is remove plus create; snapshots already written protect
 * their own history).
 */
final class RemoveUnitConversion
{
    public function __construct(
        public readonly string $conversionId,
        public readonly ?string $actorId = null,
    ) {}
}

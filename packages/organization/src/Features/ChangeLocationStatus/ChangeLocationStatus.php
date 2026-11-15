<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ChangeLocationStatus;

/**
 * Deactivate or reactivate a location (T-014 AC-24). `$isActive` is taken as
 * submitted so a non-boolean is refused as `validation`.
 */
final class ChangeLocationStatus
{
    public function __construct(
        public readonly mixed $locationId,
        public readonly mixed $isActive,
        public readonly ?string $actorId = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\UpdateLocation;

/**
 * Change the editable fields of a location (T-014 AC-23).
 */
final class UpdateLocation
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly mixed $locationId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}

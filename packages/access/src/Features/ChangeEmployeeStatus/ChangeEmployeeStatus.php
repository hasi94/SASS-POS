<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ChangeEmployeeStatus;

/**
 * One request to move an employee to another status (AC-27).
 *
 * Any declared status may follow any other (D-14): there is no transition
 * graph, because no document gives one and inventing one would invent
 * business rules - a shop re-hiring somebody marked `resigned` is ordinary.
 */
final class ChangeEmployeeStatus
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $status,
        public readonly ?string $actorId = null,
    ) {}
}

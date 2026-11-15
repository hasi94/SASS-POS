<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\GetEmployee;

/**
 * One request for one employee (AC-29).
 */
final class GetEmployee
{
    public function __construct(public readonly string $employeeId) {}
}

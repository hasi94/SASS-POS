<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ListEmployees;

/**
 * One page of the tenant's employees (AC-29).
 *
 * **Paged where the role list is not**, and the difference is a measurement
 * rather than a style: a shop has tens of roles and can have thousands of
 * employees. The size is optional; omitting it means the configured default
 * and asking for more than the configured maximum is clamped to it, never
 * refused (AC-29, `architect`'s stated choice).
 */
final class ListEmployees
{
    public function __construct(public readonly ?int $pageSize = null) {}
}

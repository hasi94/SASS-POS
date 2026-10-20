<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\GetRole;

/**
 * One request for one role (AC-23).
 */
final class GetRole
{
    public function __construct(public readonly string $roleId) {}
}

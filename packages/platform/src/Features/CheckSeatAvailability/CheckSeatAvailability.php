<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\CheckSeatAvailability;

final class CheckSeatAvailability
{
    public function __construct(public readonly string $tenantId) {}
}

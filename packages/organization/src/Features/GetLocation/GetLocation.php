<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\GetLocation;

final class GetLocation
{
    public function __construct(public readonly mixed $locationId) {}
}

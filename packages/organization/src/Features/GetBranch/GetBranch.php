<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\GetBranch;

final class GetBranch
{
    public function __construct(public readonly mixed $branchId) {}
}

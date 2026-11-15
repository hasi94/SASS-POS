<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\CreateBranch;

final class CreateBranchResult
{
    public function __construct(
        public readonly string $branchId,
        public readonly string $locationId,
        public readonly string $code,
    ) {}
}

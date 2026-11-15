<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListBranches;

final class ListBranches
{
    public function __construct(public readonly ?int $pageSize = null) {}
}

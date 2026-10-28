<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ResolvePlanLimit;

use Flexipos\Contracts\Platform\LimitKey;

final class ResolvePlanLimit
{
    public function __construct(public readonly LimitKey $key) {}
}

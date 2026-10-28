<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ResolveFeatureFlag;

final class ResolveFeatureFlag
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $key,
    ) {}
}

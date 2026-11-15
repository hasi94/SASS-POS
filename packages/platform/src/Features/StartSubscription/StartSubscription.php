<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\StartSubscription;

final class StartSubscription
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $planId,
        public readonly string $status,
        public readonly ?string $actorUserId = null,
    ) {}
}

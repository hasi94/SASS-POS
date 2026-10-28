<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeSubscriptionStatus;

final class ChangeSubscriptionStatus
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $status,
        public readonly ?string $cancellationReason = null,
        public readonly ?string $actorUserId = null,
    ) {}
}

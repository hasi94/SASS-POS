<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeSubscriptionPlan;

final class ChangeSubscriptionPlan
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $planId,
        public readonly ?string $actorUserId = null,
    ) {}
}

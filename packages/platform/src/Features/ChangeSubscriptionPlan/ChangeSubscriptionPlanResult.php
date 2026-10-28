<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeSubscriptionPlan;

final class ChangeSubscriptionPlanResult
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $planId,
    ) {}
}

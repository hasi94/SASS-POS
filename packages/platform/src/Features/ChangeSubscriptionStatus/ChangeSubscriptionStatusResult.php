<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeSubscriptionStatus;

final class ChangeSubscriptionStatusResult
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly ?string $trigger,
    ) {}
}

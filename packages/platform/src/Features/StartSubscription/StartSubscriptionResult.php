<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\StartSubscription;

final class StartSubscriptionResult
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly ?string $trigger,
    ) {}
}

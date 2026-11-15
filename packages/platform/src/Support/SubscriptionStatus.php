<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

/**
 * The closed set `subscriptions.status` holds (docs/database.md section 4.2).
 */
final class SubscriptionStatus
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const PAUSED = 'paused';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** @var list<string> */
    public const ALL = [
        self::TRIAL, self::ACTIVE, self::PAST_DUE, self::PAUSED, self::CANCELLED, self::EXPIRED,
    ];
}

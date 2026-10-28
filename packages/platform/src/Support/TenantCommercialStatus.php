<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

/**
 * The closed sets `CommercialStatus::target()` and `TenantStatusSync` share
 * (T-016 Ruling 3, Ruling 4, D-16).
 */
final class TenantCommercialStatus
{
    public const SUSPENDED = 'suspended';

    public const READ_ONLY = 'read_only';

    public const ACTIVE = 'active';

    public const TRIAL = 'trial';

    public const PAST_DUE = 'past_due';

    /**
     * Statuses the reconciler never touches in either direction (Ruling 3):
     * `closed`/`archived` (AC-10) and `pending`/`provisioning` (FP-206).
     *
     * @var list<string>
     */
    public const PROTECTED = ['closed', 'archived', 'pending', 'provisioning'];

    /**
     * The only `suspension_reason` values the reconciler itself ever writes,
     * and therefore the only ones it may ever lift (D-16, AC-10a).
     *
     * @var list<string>
     */
    public const SENTINELS = ['subscription_lapsed', 'licence_lapsed', 'past_due_grace_expired'];
}

<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

/**
 * The closed list of `platform_audit_logs.action` values this package writes
 * (T-017 Ruling 3): the seven T-016 handlers already pass, plus
 * `migration.run` (AC-14).
 *
 * **Closed, not published through a catalogue.** Unlike the tenant side,
 * nothing outside this package may write `platform_audit_logs` at all -
 * {@see PlatformAudit} is the only inserter - so there is no second provider
 * for a shared catalogue to collect from. {@see PlatformAudit::write()}
 * checks directly against this list.
 */
final class PlatformAuditActions
{
    // ChangeLicenceStatusHandler
    public const LICENCE_STATUS_CHANGED = 'licence_status_changed';

    // ChangeSubscriptionPlanHandler
    public const SUBSCRIPTION_PLAN_CHANGED = 'subscription_plan_changed';

    // ChangeSubscriptionStatusHandler
    public const SUBSCRIPTION_STATUS_CHANGED = 'subscription_status_changed';

    // SetTenantFeatureFlagHandler (clear)
    public const TENANT_FEATURE_FLAG_CLEARED = 'tenant_feature_flag_cleared';

    // SetTenantFeatureFlagHandler (set)
    public const TENANT_FEATURE_FLAG_SET = 'tenant_feature_flag_set';

    // StartSubscriptionHandler
    public const SUBSCRIPTION_STARTED = 'subscription_started';

    // TenantStatusSync
    public const TENANT_STATUS_SYNCED = 'tenant_status_synced';

    // MigrationRunAudit (T-017 AC-14)
    public const MIGRATION_RUN = 'migration.run';

    /**
     * @var list<string>
     */
    public const ALL = [
        self::LICENCE_STATUS_CHANGED,
        self::SUBSCRIPTION_PLAN_CHANGED,
        self::SUBSCRIPTION_STATUS_CHANGED,
        self::TENANT_FEATURE_FLAG_CLEARED,
        self::TENANT_FEATURE_FLAG_SET,
        self::SUBSCRIPTION_STARTED,
        self::TENANT_STATUS_SYNCED,
        self::MIGRATION_RUN,
    ];
}

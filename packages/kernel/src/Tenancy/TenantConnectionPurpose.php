<?php

namespace Flexipos\Kernel\Tenancy;

/**
 * Why a tenant scope is being entered: to serve a request, or to migrate the
 * tenant's schema.
 *
 * The purpose selects the mapping source and nothing else. Both purposes write
 * their stanza through TenantConnectionAuthority, by the same single
 * connection-construction path (AC-60), so every T-007 isolation proof holds
 * for a migration scope exactly as it does for a request.
 *
 * It is an argument rather than a container binding that something swaps while
 * the runner runs. A mutated binding is the kind of invisible state T-007's
 * review removed, and a swap that leaked would let a request resolve the
 * migrate credential.
 *
 * **Two rules are waived by `Migrating`, and they are named here so nobody
 * reads the enum as a general bypass** (AC-19's exemption, AC-51; plan section
 * 9 finding 2, plan section 16.1):
 *
 * 1. The serving schema-version gate, **entirely**. A tenant below the required
 *    version is the only kind of tenant the runner ever migrates, so a gate
 *    that refused it would refuse the runner its only job.
 * 2. The status gate, **over D-11's four statuses only** - the list
 *    {@see self::MIGRATING_STATUSES}. The planner accepts those four, so a gate
 *    that accepted only `active` would write a job row for three statuses that
 *    then cannot be entered; and because D-12 writes `failed` after a failed
 *    offline migration, an unenterable `failed` tenant could never be retried.
 *
 * Both are exceptions and neither is a principle. Everything else - the
 * identifier check, re-entrancy, "no mapping", the driver whitelist, the
 * credential dereference and `schema_meta`'s identity check - applies to both
 * purposes identically, and `Serving` still accepts exactly `active`.
 */
enum TenantConnectionPurpose: string
{
    case Serving = 'serving';
    case Migrating = 'migrating';

    /**
     * The statuses a serving entry accepts: exactly one (D-26).
     *
     * @var list<string>
     */
    public const SERVING_STATUSES = [TenantDatabase::STATUS_ACTIVE];

    /**
     * The statuses a migrating entry accepts (D-11, D-51).
     *
     * **This array is the single authority for "which statuses may be
     * migrated".** `PlanMigrationRunHandler` derives its accepted sets from it
     * rather than restating them, because two lists that must agree will not
     * stay agreed - that disagreement was BUG-1.
     *
     * It is an **allow list, deny by default**: `suspended`, `moving`,
     * `restoring`, `archived`, `migrating` and every unrecognised value are
     * refused for both purposes. `migrating` is excluded deliberately - a
     * worker killed mid-offline-run leaves a tenant no later run can enter, and
     * the operator's path is a repair plus a status correction in the master
     * (recorded, not scheduled; plan section 16.1).
     *
     * @var list<string>
     */
    public const MIGRATING_STATUSES = ['active', 'read_only', 'provisioning', 'failed'];

    /**
     * The statuses a scope entry for this purpose accepts.
     *
     * @return list<string>
     */
    public function acceptedStatuses(): array
    {
        return match ($this) {
            self::Serving => self::SERVING_STATUSES,
            self::Migrating => self::MIGRATING_STATUSES,
        };
    }

    /**
     * Deny by default: true only for a status this purpose names.
     */
    public function permits(string $status): bool
    {
        return in_array($status, $this->acceptedStatuses(), true);
    }
}

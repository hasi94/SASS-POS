<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

/**
 * The eleven steps of a `create` provisioning run, in D-20's order (plan
 * Ruling 2, AC-2).
 *
 * This is the one place the order is written down. `ProvisioningRuns::plan()`
 * writes one `tenant_provisioning_steps` row per case, in `self::cases()`
 * order, and nothing else is permitted to restate the sequence - a test that
 * wants "every step, in order" asks this enum rather than copying the list a
 * second time.
 */
enum StepName: string
{
    case ReserveIdentity = 'reserve_identity';
    case SelectServer = 'select_server';
    case CreateDatabase = 'create_database';
    case StoreCredentials = 'store_credentials';
    case CreateUsers = 'create_users';
    case WriteMapping = 'write_mapping';
    case MigrateRemainder = 'migrate_remainder';
    case SeedCore = 'seed_core';
    case CreateOwner = 'create_owner';
    case Verify = 'verify';
    case Activate = 'activate';

    /**
     * The full D-20 sequence, as a plain list of values - what a fresh
     * `create` run is planned with.
     *
     * @return list<self>
     */
    public static function order(): array
    {
        return self::cases();
    }
}

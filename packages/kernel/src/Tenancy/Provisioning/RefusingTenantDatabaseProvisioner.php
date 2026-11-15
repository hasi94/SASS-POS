<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\Exceptions\ProvisioningNotConfigured;
use Flexipos\Kernel\Tenancy\Secrets\Secret;

/**
 * The default binding (T-026 Ruling 4): throws "no provisioner configured"
 * on every operation, reaching no server at all.
 *
 * This is what a slice fails closed into if it forgets to bind
 * {@see RecordingTenantDatabaseProvisioner} - the real driver is bound only
 * by `tenancy.provisioning.driver=mysql`, which `phpunit.xml` never sets.
 */
final class RefusingTenantDatabaseProvisioner implements TenantDatabaseProvisioner
{
    public function databaseState(ProvisioningServer $server, string $name): DatabaseState
    {
        throw ProvisioningNotConfigured::because('databaseState');
    }

    public function createDatabase(ProvisioningServer $server, string $name, string $charset, string $collation): void
    {
        throw ProvisioningNotConfigured::because('createDatabase');
    }

    public function userExists(ProvisioningServer $server, string $user): bool
    {
        throw ProvisioningNotConfigured::because('userExists');
    }

    public function createUser(
        ProvisioningServer $server,
        string $user,
        Secret $password,
        TenantGrant $grant,
        string $database,
    ): void {
        throw ProvisioningNotConfigured::because('createUser');
    }

    public function dropUser(ProvisioningServer $server, string $user): void
    {
        throw ProvisioningNotConfigured::because('dropUser');
    }
}

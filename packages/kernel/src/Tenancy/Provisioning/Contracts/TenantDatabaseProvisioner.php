<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning\Contracts;

use Flexipos\Kernel\Tenancy\Provisioning\DatabaseState;
use Flexipos\Kernel\Tenancy\Provisioning\Exceptions\ProvisioningNotConfigured;
use Flexipos\Kernel\Tenancy\Provisioning\Exceptions\TenantNameRejected;
use Flexipos\Kernel\Tenancy\Provisioning\TenantGrant;
use Flexipos\Kernel\Tenancy\Secrets\Secret;

/**
 * `CREATE DATABASE`/`CREATE USER`/`GRANT`, behind one published contract
 * (T-026 AC-15, Ruling 4).
 *
 * Three implementations: {@see \Flexipos\Kernel\Tenancy\Provisioning\MySqlTenantDatabaseProvisioner}
 * (the real one), {@see \Flexipos\Kernel\Tenancy\Provisioning\RecordingTenantDatabaseProvisioner}
 * (the suite stand-in, bound by default in tests) and
 * {@see \Flexipos\Kernel\Tenancy\Provisioning\RefusingTenantDatabaseProvisioner}
 * (the default binding everywhere else, which throws). The real
 * implementation is bound only when `tenancy.provisioning.driver=mysql` is
 * set explicitly - never by the test suite - so a caller that forgets to
 * bind the stand-in fails closed under ENGINE_HOLD instead of reaching a
 * live server.
 *
 * It does **not** generate passwords - that is `store_credentials`'/
 * {@see \Flexipos\Kernel\Tenancy\Secrets\CredentialGenerator}'s job,
 * deliberately split out so a password exists and is durably stored before
 * anything needs it (D-20).
 */
interface TenantDatabaseProvisioner
{
    /**
     * @throws TenantNameRejected
     * @throws ProvisioningNotConfigured
     */
    public function databaseState(ProvisioningServer $server, string $name): DatabaseState;

    /**
     * @throws TenantNameRejected
     * @throws ProvisioningNotConfigured
     */
    public function createDatabase(ProvisioningServer $server, string $name, string $charset, string $collation): void;

    /**
     * @throws TenantNameRejected
     * @throws ProvisioningNotConfigured
     */
    public function userExists(ProvisioningServer $server, string $user): bool;

    /**
     * `CREATE USER IF NOT EXISTS`, `ALTER USER … IDENTIFIED BY` and
     * `GRANT … ON \`db\`.*`, all idempotent.
     *
     * @throws TenantNameRejected
     * @throws ProvisioningNotConfigured
     */
    public function createUser(
        ProvisioningServer $server,
        string $user,
        Secret $password,
        TenantGrant $grant,
        string $database,
    ): void;

    /**
     * @throws TenantNameRejected
     * @throws ProvisioningNotConfigured
     */
    public function dropUser(ProvisioningServer $server, string $user): void;
}

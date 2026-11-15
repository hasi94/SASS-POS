<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning\Contracts;

/**
 * The `database_servers` facts {@see TenantDatabaseProvisioner} needs to
 * reach a server (T-026 Ruling 4): not the whole row, only what connecting
 * and naming require.
 */
final readonly class ProvisioningServer
{
    public function __construct(
        public string $code,
        public string $host,
        public int $port,
        public string $engine,
        public string $tlsMode,
        public string $provisionerCredentialRef,
    ) {}
}

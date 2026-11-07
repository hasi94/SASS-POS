<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning\Exceptions;

use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;

/**
 * The default binding of {@see TenantDatabaseProvisioner} (T-026 Ruling 4):
 * no real engine driver is configured, so every operation refuses rather
 * than reaching a live server.
 *
 * `composer test:fast`/`composer test` never set
 * `tenancy.provisioning.driver=mysql`, so a slice that forgets to bind the
 * recording stand-in fails closed here instead of connecting to
 * 127.0.0.1:3306 (ENGINE_HOLD).
 */
final class ProvisioningNotConfigured extends TenancyException
{
    public static function because(string $operation): self
    {
        return new self(sprintf(
            'No real database provisioner is configured (operation: %s). Set tenancy.provisioning.driver=mysql '
            .'to bind one, or bind the recording stand-in in a test.',
            $operation
        ));
    }
}

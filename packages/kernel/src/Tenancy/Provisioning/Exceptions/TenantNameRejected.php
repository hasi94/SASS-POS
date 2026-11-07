<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning\Exceptions;

use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;

/**
 * A database or user identifier failed the guard at the point of effect
 * (T-026 Ruling 4).
 *
 * Deliberately never caught and silenced anywhere: the whole point of the
 * two-check guard is that a bad identifier never reaches a statement.
 */
final class TenantNameRejected extends TenancyException
{
    public static function because(string $name, string $reason): self
    {
        return new self(sprintf(
            'The identifier [%s] is not a name this provisioner may act on: %s',
            $name,
            $reason
        ));
    }
}

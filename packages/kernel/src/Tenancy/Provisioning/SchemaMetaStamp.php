<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

use Flexipos\Kernel\Schema\Exceptions\TenantIdentityMismatch;
use Flexipos\Kernel\Schema\Features\MigrateTenant\SchemaMetaIdentity;
use Illuminate\Database\Connection;

/**
 * The write half of {@see SchemaMetaIdentity} (T-026 AC-25a, D-28, D-22).
 *
 * `SchemaMetaIdentity::of()` already reads this row and
 * `TenantIdentityMismatch` already exists as the refusal T-008's runner
 * raises when it disagrees with the mapping - both built anticipating this
 * writer, which `seed_core` is. Three outcomes:
 *
 * - **absent**: a write - this tenant's first row in `schema_meta`;
 * - **equal**: a no-op, so a resumed `seed_core` finds its own stamp;
 * - **a different tenant**: {@see TenantIdentityMismatch}, with nothing
 *   written - the same refusal the migration runner raises for the same
 *   reason, read through the one shared identity.
 */
final class SchemaMetaStamp
{
    public static function stamp(Connection $connection, string $tenantId): void
    {
        $existing = SchemaMetaIdentity::of($connection);

        if ($existing === $tenantId) {
            return;
        }

        if ($existing !== null) {
            throw TenantIdentityMismatch::between($tenantId, $existing, (string) $connection->getDatabaseName());
        }

        $connection->table(SchemaMetaIdentity::TABLE)->insert([
            'key' => SchemaMetaIdentity::KEY,
            'value' => $tenantId,
        ]);
    }
}

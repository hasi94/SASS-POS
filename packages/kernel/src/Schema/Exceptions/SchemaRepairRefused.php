<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Exceptions;

use RuntimeException;

/**
 * The repair refused to correct a mapping.
 *
 * **Named for the site that throws it, not for the condition it is about.**
 * The plan originally called this `TenantSchemaAhead`, after AC-55's wording,
 * and a criterion makes a bad class name here for the reason step 7 already
 * paid for once: an exception named after a criterion is written where the
 * criterion is read rather than where something refuses, and it ends up being
 * dead code with a misleading name. This one is thrown in exactly one place -
 * `RepairSchemaVersionHandler`, on a mapping ahead of its own database.
 *
 * **Why ahead is a refusal while behind is a repair.** A mapping *behind* its
 * tenant's own `migrations` table is a crash between two writes: the tenant
 * really is at the higher version and raising the mirror loses nothing. A
 * mapping *ahead* of it says the opposite - the mirror claims migrations the
 * database cannot show - and the only correction available to code is to
 * lower the mirror, which would quietly make a restored-from-an-old-backup or
 * partially-restored database look healthy. That is a decision for a human
 * with the backups in front of them, so the repair writes the status that
 * stops the tenant serving and then says so here.
 */
final class SchemaRepairRefused extends RuntimeException
{
    public static function mirrorAheadOfTenant(
        string $tenantId,
        int $mappingVersion,
        int $appliedVersion,
        string $status,
    ): self {
        return new self(
            'The mapping for tenant ['.$tenantId.'] claims schema version '.$mappingVersion.' but the'
            .' database itself carries '.$appliedVersion.', so the mirror is ahead of the tenant and'
            .' the repair refuses: lowering it would make a database that has lost migrations look'
            .' healthy. The mapping status is now ['.$status.'], so the tenant is not served until'
            .' somebody decides between restoring the database and accepting the lower version.'
        );
    }
}

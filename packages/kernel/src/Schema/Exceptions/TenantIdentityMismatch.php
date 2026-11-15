<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Exceptions;

use RuntimeException;

/**
 * The database the runner opened says it belongs to a different tenant
 * (AC-56).
 *
 * `schema_meta` holds the tenant id as a **row**, because a tenant database
 * has no `tenant_id` column anywhere (AC-24): the database *is* the boundary.
 * When that row names somebody else, the mapping and the database disagree
 * about which shop this is, and the only safe reading is that a mapping
 * points at the wrong database - a moved tenant, a restored copy, a hand-made
 * row. Migrating it would apply one shop's schema change to another shop's
 * data.
 *
 * **What this is and is not** (D-28). It is a read-only identity check in the
 * runner. T-008 writes nothing into `schema_meta`, so for every tenant this
 * task can produce the *no row* path is the shipped path and this check has
 * nothing to contradict; the mismatch is constructed by a test that plants a
 * row. The interlock `docs/database.md` 6.21 describes - assert the identity
 * before every write in the request path - needs the writer, which is
 * FP-206's, and is not delivered here.
 *
 * It is raised before anything is applied, so the refusal leaves that
 * database exactly as it was.
 */
final class TenantIdentityMismatch extends RuntimeException
{
    public static function between(string $expected, string $found, string $database): self
    {
        return new self(
            'The database ['.$database.'] carries a schema_meta row naming tenant ['.$found.'], but'
            .' the mapping opened it for tenant ['.$expected.']. Nothing was applied to it. One of'
            .' the two is wrong and only a human can say which: check whether this mapping points at'
            .' a restored or moved database.'
        );
    }
}

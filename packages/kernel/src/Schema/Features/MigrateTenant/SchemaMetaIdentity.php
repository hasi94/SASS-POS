<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\MigrateTenant;

use Illuminate\Database\Connection;

/**
 * Which tenant a database says it belongs to (AC-56).
 *
 * `schema_meta` is a key/value table and the identity lives at the key
 * `tenant_id` as a **row**, because a tenant database has no `tenant_id`
 * column anywhere (AC-24). This reader is the only thing in T-008 that
 * touches it, and it only reads: the writer belongs to FP-206's provisioning
 * step (D-28).
 *
 * **Three states, and the third is the shipped one.** A matching row, a
 * mismatching row, and *no row at all* - which is what every tenant this task
 * can produce looks like, because the baseline creates `schema_meta` empty.
 * Absent is deliberately not treated as a mismatch: there is nothing to
 * contradict, and a check that refused on absence would refuse every tenant
 * on the platform.
 */
final class SchemaMetaIdentity
{
    public const TABLE = 'schema_meta';

    public const KEY = 'tenant_id';

    /**
     * The tenant this database claims to be, or null when it does not say.
     */
    public static function of(Connection $connection): ?string
    {
        if (! $connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            return null;
        }

        $row = $connection->table(self::TABLE)->where('key', self::KEY)->first();

        if ($row === null) {
            return null;
        }

        $value = ((array) $row)['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

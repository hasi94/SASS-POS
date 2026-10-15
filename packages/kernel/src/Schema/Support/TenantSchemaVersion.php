<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

use Illuminate\Database\Connection;

/**
 * What version a tenant database actually carries (D-14).
 *
 * **The tenant's own `migrations` table is the authority and
 * `tenant_databases.schema_version` is the mirror of it.** That is a decision,
 * not an implementation detail, and this class is the one site that states it:
 * the drift check and the repair both ask the same question, and two copies of
 * "what a tenant's version is" would be two answers the day one of them
 * learned about a set with two directories.
 *
 * It is in `Support/` with two callers rather than the three that directory's
 * rule asks for, and the reason is that rule's own purpose: the alternative is
 * not one caller, it is two copies of D-14.
 *
 * A version is a **position in a set**, so the answer is the highest version
 * of any migration the tenant lists that the set knows. A row naming a
 * migration the set has never heard of is ignored rather than refused - a
 * tenant migrated by a different set is a question for the drift report, and
 * a reader that threw here would make the report unable to describe it.
 */
final class TenantSchemaVersion
{
    /**
     * The table Laravel's own migrator keeps, and the only table this class
     * reads.
     */
    public const TABLE = 'migrations';

    /**
     * The highest set version the tenant's own table lists; zero for a
     * database the baseline has never reached.
     */
    public static function of(Connection $connection, MigrationSet $set): int
    {
        $highest = 0;

        foreach (self::appliedTo($connection) as $migration) {
            $version = $set->versionOf($migration);

            if ($version !== null && $version > $highest) {
                $highest = $version;
            }
        }

        return $highest;
    }

    /**
     * Every migration the tenant's own table lists, in the order it applied
     * them.
     *
     * A database with no such table lists nothing. That is the empty tenant,
     * not an error: it is the state tenant A of the three-tenant fixture is
     * in, and the state every tenant is in before its first run.
     *
     * @return list<string>
     */
    public static function appliedTo(Connection $connection): array
    {
        if (! $connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            return [];
        }

        $applied = [];

        foreach ($connection->table(self::TABLE)->orderBy('id')->get() as $row) {
            $applied[] = (string) ((array) $row)['migration'];
        }

        return $applied;
    }
}

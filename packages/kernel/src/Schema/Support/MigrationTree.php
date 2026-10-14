<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

/**
 * Which of the two migration trees a file belongs to.
 *
 * Two of the static rules are asymmetric and cannot be checked without it: a
 * master migration may not name the `tenant` connection, a tenant migration may
 * not name the `master` one (AC-37), and only a tenant migration is forbidden to
 * declare a `tenant_id` column (AC-24 - the master's tables carry it on purpose,
 * because the master is where a tenant id belongs).
 *
 * `forPath()` classifies a real file; a fixture hands the case in directly, so
 * that a rule can be proven on text that is not in either shipped tree.
 */
enum MigrationTree: string
{
    case Master = 'master';
    case Tenant = 'tenant';

    /**
     * The tree a migration path belongs to.
     *
     * The tenant directory is tested first: its name contains the master
     * directory's name as a prefix (`migrations` / `migrations-tenant`), so the
     * other order classifies every tenant migration as a master one.
     */
    public static function forPath(string $path): self
    {
        $normalised = str_replace('\\', '/', $path);

        return str_contains($normalised, '/migrations-tenant/') ? self::Tenant : self::Master;
    }
}

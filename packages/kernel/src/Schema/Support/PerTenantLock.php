<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

use Flexipos\Kernel\Schema\Exceptions\TenantLockUnsupported;
use Illuminate\Database\Connection;

/**
 * The advisory lock one runner holds while it is inside one tenant (T-008
 * step 10, AC-49).
 *
 * `GET_LOCK(name, 0)` and not a row lock, and the zero is the whole point:
 * the runner must not **wait**. AC-49 says a tenant whose lock is held by
 * someone else is reported as not run and the rest of the run carries on; a
 * blocking acquire would turn one busy tenant into a stalled run.
 *
 * It is not `SELECT ... FOR UPDATE SKIP LOCKED`, because that does not exist
 * here. MEASURED by the lead on this machine: `SKIP LOCKED` is a syntax error
 * on MariaDB 10.4.32 (it arrived in 10.6), while `NOWAIT` parses and
 * `GET_LOCK(name, 0)` returns immediately. D-25.
 *
 * **The lock is taken on the tenant connection**, which is what makes it a
 * lock on the tenant rather than on a name in some shared master: a MariaDB
 * advisory lock is per server connection and the lock name is scoped by hand,
 * so the name carries the tenant id.
 *
 * **What is verified and what is not.** The lock name is a pure function and
 * is proven here. Whether a second session is actually refused is an engine
 * criterion - AC-49 and AC-65 - and it is **NOT VERIFIED**: `ENGINE_HOLD` is
 * set and gate G-1 is unmet, so no test in this branch has ever seen two
 * sessions. On a driver with no `GET_LOCK` this class throws
 * {@see TenantLockUnsupported} rather than reporting a lock it does not hold.
 */
final class PerTenantLock
{
    /**
     * The drivers that have `GET_LOCK`.
     *
     * @var list<string>
     */
    public const SUPPORTED_DRIVERS = ['mysql', 'mariadb'];

    /**
     * MariaDB truncates a lock name at 64 characters, so the name is a fixed
     * prefix and a 26-character ULID and is never built from anything whose
     * length is not known.
     */
    public const PREFIX = 'flexipos_tenant_migrate_';

    /**
     * The lock name for one tenant. A pure function, so the one thing both
     * sessions have to agree on is provable with no engine at all.
     */
    public static function nameFor(string $tenantId): string
    {
        return self::PREFIX.$tenantId;
    }

    /**
     * Take the lock, or report that somebody else holds it. Never waits.
     *
     * @throws TenantLockUnsupported when the connection cannot grant the lock
     */
    public static function acquire(Connection $tenant, string $tenantId): bool
    {
        $name = self::nameFor($tenantId);

        self::assertSupported($tenant, $name);

        $row = $tenant->selectOne('select get_lock(?, 0) as granted', [$name]);

        return ((int) ((array) $row)['granted']) === 1;
    }

    /**
     * Give the lock back. A connection that is closed releases it anyway,
     * which is the property that makes a dead worker harmless.
     *
     * @throws TenantLockUnsupported when the connection cannot grant the lock
     */
    public static function release(Connection $tenant, string $tenantId): bool
    {
        $name = self::nameFor($tenantId);

        self::assertSupported($tenant, $name);

        $row = $tenant->selectOne('select release_lock(?) as released', [$name]);

        return ((int) ((array) $row)['released']) === 1;
    }

    /**
     * @throws TenantLockUnsupported
     */
    private static function assertSupported(Connection $tenant, string $name): void
    {
        $driver = $tenant->getDriverName();

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw TenantLockUnsupported::onDriver($driver, $name);
        }
    }
}

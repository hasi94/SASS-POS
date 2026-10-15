<?php

declare(strict_types=1);

namespace Tests\Fixtures\TenantMigrations;

use Closure;
use Flexipos\Kernel\Schema\Support\MigrationSet;

/**
 * What an FX migration saw while it ran, and the one thing a test tells it to
 * do (T-008 step 8).
 *
 * Several criteria are about what is true **from inside `up()`** rather than
 * after it, and that is not an implementation detail of the test - it is the
 * only input at which the claim is distinguishable:
 *
 * - AC-39: no tenant database is touched before the plan is written, observed
 *   as the job-row count FX-1 sees on its first tenant;
 * - AC-52: the tenant's mapping status is `migrating` *during* an offline
 *   migration, which is indistinguishable from `active` once the migration has
 *   finished;
 * - AC-44: FX-2 fails on one tenant only, which needs the migration to know
 *   which tenant it is running against.
 *
 * Static, because a migration is instantiated by the framework's migrator and
 * there is nowhere to inject anything. {@see reset()} is therefore mandatory in
 * the `beforeEach` of anything that reads it: static state that survives
 * between cases is how one test starts passing because of another.
 *
 * It lives beside the FX directories and not inside one: everything inside an
 * FX directory is a migration, because {@see MigrationSet}
 * takes every `*.php` file in it.
 */
final class FixtureMigrationLog
{
    /** @var list<array{event: string, value: mixed}> */
    private static array $entries = [];

    /**
     * The tenant database name FX-2 is told to fail on, or null for "fail on
     * nobody".
     */
    private static ?string $failOn = null;

    /**
     * Forget everything, including the FX-2 instruction.
     */
    /**
     * What an FX migration asks for when it wants to know something only the
     * test around it can see.
     *
     * @var Closure(string): mixed|null
     */
    private static ?Closure $probe = null;

    /**
     * Forget everything, including the FX-2 instruction and the probe.
     */
    public static function reset(): void
    {
        self::$entries = [];
        self::$failOn = null;
        self::$probe = null;
    }

    /**
     * Install what {@see observe()} asks.
     *
     * The probe exists so that a migration never reads another connection.
     * AC-39's observable is the **master's** job-row count as seen from inside
     * a tenant migration, and AC-52's is the master's `tenant_databases.status`
     * - but AC-37 forbids a migration naming another connection, and an FX file
     * that broke that rule could not also be used as the control proving the
     * rule is silent on a clean fixture. So the cross-connection read lives in
     * the test, where it belongs, and the migration only says *when* to take
     * it.
     *
     * @param  Closure(string): mixed|null  $probe
     */
    public static function observeWith(?Closure $probe): void
    {
        self::$probe = $probe;
    }

    /**
     * Record whatever the installed probe answers for this event, or null when
     * no probe is installed.
     */
    public static function observe(string $event): void
    {
        self::record($event, self::$probe instanceof Closure ? (self::$probe)($event) : null);
    }

    /**
     * Record one observation.
     */
    public static function record(string $event, mixed $value = null): void
    {
        self::$entries[] = ['event' => $event, 'value' => $value];
    }

    /**
     * Every observation, in the order it was made.
     *
     * @return list<array{event: string, value: mixed}>
     */
    public static function entries(): array
    {
        return self::$entries;
    }

    /**
     * The values recorded under one event name, in order.
     *
     * @return list<mixed>
     */
    public static function valuesFor(string $event): array
    {
        $values = [];

        foreach (self::$entries as $entry) {
            if ($entry['event'] === $event) {
                $values[] = $entry['value'];
            }
        }

        return $values;
    }

    /**
     * Tell FX-2 which tenant database to throw on.
     *
     * By database name rather than by tenant id, because a migration runs
     * inside the tenant connection and the database name is what it can see
     * without reaching back into the master.
     */
    public static function failOnDatabase(?string $database): void
    {
        self::$failOn = $database;
    }

    /**
     * Whether FX-2 should throw on the database it is currently running
     * against.
     *
     * Compared with `str_ends_with` so a fixture may be told either the full
     * path of a SQLite file or a MySQL database name, which is what makes the
     * same FX file usable on the stand-in and on the engine.
     */
    public static function shouldFailOn(string $database): bool
    {
        return is_string(self::$failOn)
            && self::$failOn !== ''
            && ($database === self::$failOn || str_ends_with($database, self::$failOn));
    }
}

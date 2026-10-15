<?php

declare(strict_types=1);

namespace Flexipos\Testing\Engine;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The engine connections the test suite is allowed to open.
 *
 * Three properties, each of them load bearing:
 *
 * - **It goes through the framework's connector.** Never a bare PDO. The server
 *   this project develops against reports
 *   `@@sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` — no
 *   strict mode at all — and Laravel's `'strict' => true` is what sets it per
 *   session. On a hand-built PDO an over-long value is truncated silently, so
 *   every criterion whose evidence is "the write was refused" would pass on a
 *   truncation it never observed (AC-68).
 * - **It fails, it never skips** (AC-63). An unreachable engine throws
 *   {@see EngineUnreachable} with the engine named.
 * - **It writes `SELECT VERSION()` into the test output** (AC-63), so a report
 *   can say which engine a criterion was verified on.
 *
 * The stanza is read from the environment the suite declares
 * (`ENGINE_DRIVER`, `ENGINE_HOST`, `ENGINE_PORT`, `ENGINE_USERNAME`,
 * `ENGINE_PASSWORD`), so nothing here depends on the machine it runs on.
 */
final class EngineConnection
{
    /** Connection name used when no database is named (server-level work). */
    public const SERVER_CONNECTION = 'flexipos_engine';

    private static bool $versionAnnounced = false;

    /**
     * The connection stanza, as the shipped MySQL/MariaDB stanzas are written.
     *
     * @return array<string, mixed>
     */
    public static function stanza(string $database = ''): array
    {
        return [
            'driver' => self::driver(),
            'host' => self::environment('ENGINE_HOST', '127.0.0.1'),
            'port' => self::environment('ENGINE_PORT', '3306'),
            'database' => $database,
            'username' => self::environment('ENGINE_USERNAME', 'root'),
            'password' => self::environment('ENGINE_PASSWORD', ''),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            // utf8mb4_unicode_ci, not utf8mb4_0900_ai_ci: the engine in scope is
            // MariaDB 10.4, which has neither (config/database.php says the same).
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            // The reason this class exists. See the class docblock.
            'strict' => true,
            'engine' => null,
            'options' => [],
        ];
    }

    /**
     * A live connection to the engine, optionally inside one database.
     *
     * Passing a database name asserts it is inside the reserved test space
     * first: a connection is the step before a write, and a write into someone
     * else's schema is the thing this package exists to prevent.
     */
    public static function connection(string $database = ''): Connection
    {
        self::assertNotOnHold();

        if ($database !== '') {
            ReservedDatabaseName::assert($database);
        }

        $name = self::connectionName($database);

        Config::set('database.connections.'.$name, self::stanza($database));

        DB::purge($name);

        $connection = DB::connection($name);

        try {
            $connection->select('select 1 as reachable');
        } catch (Throwable $exception) {
            DB::purge($name);

            throw EngineUnreachable::at(
                self::driver(),
                (string) self::environment('ENGINE_HOST', '127.0.0.1'),
                (string) self::environment('ENGINE_PORT', '3306'),
                $database,
                $exception
            );
        }

        self::announceVersion($connection);

        return $connection;
    }

    /**
     * `SELECT VERSION()` on the engine, as the server reports it.
     */
    public static function version(?Connection $connection = null): string
    {
        $connection ??= self::connection();

        /** @var array<int, object{version: string|null}> $rows */
        $rows = $connection->select('select version() as version');

        return (string) ($rows[0]->version ?? 'unknown');
    }

    /**
     * Write the engine and its version into the test output, once per process.
     *
     * Once, not per test: AC-63 asks for the version in the output of an engine
     * run, and one line per engine test would bury the rest of it.
     */
    public static function announceVersion(?Connection $connection = null): void
    {
        if (self::$versionAnnounced) {
            return;
        }

        self::$versionAnnounced = true;

        fwrite(STDOUT, PHP_EOL.sprintf(
            'FLEXIPOS-ENGINE driver=%s version=%s host=%s:%s',
            self::driver(),
            self::version($connection),
            (string) self::environment('ENGINE_HOST', '127.0.0.1'),
            (string) self::environment('ENGINE_PORT', '3306')
        ).PHP_EOL);
    }

    /**
     * Forget a connection, so the next caller reconnects.
     */
    public static function forget(string $database = ''): void
    {
        DB::purge(self::connectionName($database));
    }

    /**
     * Whether the engine suite is suspended.
     *
     * **It reads no environment variable.** The answer is
     * {@see EngineOnHold::HELD}, a constant, because a hold a shell variable
     * could clear is not a hold: `ENGINE_HOLD=0` in the shell used to beat
     * phpunit.xml's declaration and open a connection to the engine the
     * incident of 2026-10-14 happened on. The declaration is still in
     * phpunit.xml, forced, for every other reader of that variable.
     */
    public static function onHold(): bool
    {
        return EngineOnHold::HELD;
    }

    public static function assertNotOnHold(): void
    {
        if (self::onHold()) {
            throw EngineOnHold::sinceTheIncident();
        }
    }

    public static function driver(): string
    {
        return (string) self::environment('ENGINE_DRIVER', 'mariadb');
    }

    public static function connectionName(string $database = ''): string
    {
        return $database === ''
            ? self::SERVER_CONNECTION
            : self::SERVER_CONNECTION.'__'.$database;
    }

    private static function environment(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}

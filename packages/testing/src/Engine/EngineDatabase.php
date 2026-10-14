<?php

declare(strict_types=1);

namespace Flexipos\Testing\Engine;

use Illuminate\Database\Connection;

/**
 * The only code in this repository permitted to issue `CREATE DATABASE` or
 * `DROP DATABASE`.
 *
 * Three rules, each written against something measured rather than feared.
 *
 * **1. The name is checked twice, by two independent pieces of code, at the
 * point of effect.** {@see ReservedDatabaseName::assert()} owns the rule, and
 * {@see assertOwnPrefix()} below re-checks the prefix with its own literal,
 * immediately before the statement. On 2026-10-14 a deliberately permissive
 * stub of the guard class - written to prove the guard's test red, and believed
 * harmless because "nothing drops anything yet" - was handed to an engine-wide
 * sweep and dropped sixteen of the owner's databases in alphabetical order.
 * One class being wrong must not be enough to destroy data. The check is at the
 * point of effect and not at planning time for the same reason: between a check
 * and a statement, a name can be rebuilt, concatenated or read from a fixture.
 *
 * **2. There is no engine-wide sweep anywhere in this repository, and there
 * must not be one.** This class drops names its caller passes in, one at a
 * time, and {@see recreate()} is what makes a leftover harmless, because it
 * drops *that one name* before creating it. tools/clean-engine.php used to
 * sweep and is now report-only: it lists leftover reserved databases, drops
 * nothing and exits 0. A sweep is the one operation whose blast radius is the
 * whole engine when its filter is wrong, and no ceiling repairs that, because a
 * filter that wrongly returns twenty foreign names passes any plausible
 * ceiling. This class is the only file in the repository permitted to issue a
 * database drop at all, and that is enforced by
 * modules/host/tests/Feature/DestructiveStatementContainmentTest.php rather
 * than by this docblock.
 *
 * **3. Enumeration reads `information_schema.schemata` and filters in PHP.**
 * SQL `LIKE` is forbidden: `_` is a single-character wildcard, so on this engine
 * `SHOW DATABASES LIKE 'flexipos_%'` returns `flexipos-master` and
 * `flexipos-master-new`. The escaped form returns nothing, and
 * `COLLATE utf8mb4_bin` on `schema_name` errors outright on MariaDB 10.4, so
 * neither obvious repair is available. The rule is not "escape the underscore",
 * it is "do not match names in SQL at all". `ReservedDatabaseNameGuardTest`
 * pins that expansion as a fact about the engine, so a later reader who finds
 * `LIKE` tidier breaks a test instead of a client's database.
 */
final class EngineDatabase
{
    /**
     * The reserved prefix again, as an independent literal.
     *
     * Deliberately not ReservedDatabaseName::PREFIX. Reading the constant would
     * make this a second call to the same rule rather than a second rule, and
     * the whole point is that both have to be wrong before anything is lost.
     */
    private const PREFIX_LITERAL = 'flexipos_t8test_';

    public function __construct(private readonly Connection $connection) {}

    /**
     * The engine as the environment names it, at server level.
     */
    public static function engine(): self
    {
        return new self(EngineConnection::connection());
    }

    /**
     * Create a reserved database. Refuses any other name.
     */
    public function create(string $name): void
    {
        $this->assertOwnPrefix($name);

        $this->connection->statement('create database '.$this->quote($name));
    }

    /**
     * Create a reserved database, dropping it first if an earlier run left it
     * behind. Refuses any other name.
     */
    public function recreate(string $name): void
    {
        $this->drop($name);
        $this->create($name);
    }

    /**
     * Drop a reserved database if it exists. Refuses any other name.
     */
    public function drop(string $name): void
    {
        $this->assertOwnPrefix($name);

        $this->connection->statement('drop database if exists '.$this->quote($name));

        EngineConnection::forget($name);
    }

    public function exists(string $name): bool
    {
        return in_array($name, $this->allNames(), true);
    }

    /**
     * Every schema on the engine, read from the catalogue and never matched.
     *
     * @return list<string>
     */
    public function allNames(): array
    {
        /** @var array<int, object{schema_name: string|null}> $rows */
        $rows = $this->connection->select(
            'select schema_name from information_schema.schemata order by schema_name'
        );

        $names = [];

        foreach ($rows as $row) {
            if (is_string($row->schema_name)) {
                $names[] = $row->schema_name;
            }
        }

        return $names;
    }

    /**
     * The reserved databases currently on the engine: enumerate everything,
     * then filter in PHP.
     *
     * Read-only, on purpose. Nothing here turns this list into drops - see rule
     * 2 in the class docblock.
     *
     * @return list<string>
     */
    public function reservedNames(): array
    {
        return ReservedDatabaseName::reservedAmong($this->allNames());
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * Both checks, in the order that makes the message useful: the owning rule
     * first, then this class's own literal.
     */
    private function assertOwnPrefix(string $name): void
    {
        ReservedDatabaseName::assert($name);

        if (! str_starts_with($name, self::PREFIX_LITERAL)) {
            throw ReservedDatabaseNameViolation::because(
                $name,
                'it passed the name guard but does not start with "'.self::PREFIX_LITERAL
                .'". The two checks disagree, so the guard itself is wrong and nothing is dropped'
            );
        }
    }

    /**
     * Quote an identifier that has been through both checks, so it can only
     * contain `[a-z0-9_]`.
     */
    private function quote(string $name): string
    {
        return '`'.$name.'`';
    }
}

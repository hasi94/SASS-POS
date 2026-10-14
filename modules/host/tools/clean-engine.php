<?php

declare(strict_types=1);

use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\ReservedDatabaseName;

/*
|--------------------------------------------------------------------------
| Report the reserved test databases a killed run left behind
|--------------------------------------------------------------------------
|
| This tool is REPORT-ONLY. It lists leftover reserved-prefix databases, it
| drops nothing, and it exits 0. The sweep it used to perform was removed on
| 2026-10-14 by the ruling in docs/plans/T-008-master-schema-and-tenant-baseline.md
| section 11.2 rule 4, and the reasoning is worth keeping in front of whoever
| reads this next:
|
| - **The job the sweep existed for is already done elsewhere.**
|   Flexipos\Testing\Engine\EngineDatabase::recreate() drops *that one name*
|   before creating it, so a leftover is harmless to the next run on its own.
|   A sweep bought nothing and risked the whole engine.
| - **Bounding the capability is not as good as deleting it.** The dropping
|   version carried a ceiling of 32 names and a second prefix literal. Both
|   would have stopped the incident of 2026-10-14 - a permissive stub of the
|   name guard returned all 56 schemas on this engine to a sweep, which dropped
|   sixteen of the owner's databases, none of them backed up. But the ceiling is
|   a guess: a filter that wrongly returns twenty foreign names passes it. A
|   capability that does not exist needs no ceiling.
| - **Nothing in this repository may issue a database drop except EngineDatabase.**
|   That is now enforced by a test, not by this comment:
|   tests/Feature/DestructiveStatementContainmentTest.php.
|
| Why it still runs, and runs first in `check`: a leftover reserved database is
| evidence that a previous run was killed part-way - a Composer process timeout,
| Ctrl-C, a CI step limit - which executes no PHP teardown at all. Saying so in
| the gate's first line is cheap and it is the only place a reader will see it.
| The same argument puts tools/clean-probes.php first; `check` runs lint, stan
| and deptrac before any test, so nothing driven from a test hook ever gets to
| run ahead of the steps a leftover breaks.
|
| Two choices that survive the change to report-only:
|
| - **It enumerates `information_schema.schemata` and filters in PHP.** SQL LIKE
|   is forbidden here for the reason measured on this engine: `_` is a
|   single-character wildcard, so `SHOW DATABASES LIKE 'flexipos_%'` returns
|   `flexipos-master` and `flexipos-master-new`, two of the owner's real
|   databases. ReservedDatabaseNameGuardTest pins that expansion as a fact about
|   the engine.
| - **It is a bare PDO, not a framework connection.** It has to work when the
|   application cannot boot. It issues exactly one statement, a read of the
|   catalogue, and it never writes a row: on this server `@@sql_mode` carries no
|   strict mode, and only the framework's connector sets one per session
|   (AC-68). The environment contract itself is still read from
|   EngineConnection::stanza(), so the variable names have exactly one home.
|
| An unreachable engine is a warning here and not a failure: there is nothing to
| report, and the engine tests themselves fail with the engine named (AC-63), so
| the gate still reports it where a reader can act on it.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
 * The reserved prefix again, as an independent literal, deliberately not
 * ReservedDatabaseName::PREFIX. There is no destructive statement left in this
 * file for it to stand in front of; what it still does is catch the two rules
 * disagreeing. If the guard class ever calls a name reserved that this literal
 * does not, the guard class is wrong - that is exactly the shape of the stub
 * that caused the incident - and the gate should stop on it rather than print a
 * report derived from it.
 */
const CLEAN_ENGINE_PREFIX_LITERAL = 'flexipos_t8test_';

if (EngineConnection::onHold()) {
    fwrite(STDOUT, 'clean-engine: the engine suite is on hold (ENGINE_HOLD), so nothing was read and '
        .'no connection was opened. See Flexipos\\Testing\\Engine\\EngineOnHold.'.PHP_EOL);

    exit(0);
}

/** @var array<string, mixed> $stanza */
$stanza = EngineConnection::stanza();

$host = is_scalar($stanza['host'] ?? null) ? (string) $stanza['host'] : '127.0.0.1';
$port = is_scalar($stanza['port'] ?? null) ? (string) $stanza['port'] : '3306';
$username = is_scalar($stanza['username'] ?? null) ? (string) $stanza['username'] : 'root';
$password = is_scalar($stanza['password'] ?? null) ? (string) $stanza['password'] : '';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s', $host, $port),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        'clean-engine: the engine at %s:%s is unreachable, so no reserved test database could be '
        .'reported. The engine tests will fail with the engine named. Underlying error: %s'.PHP_EOL,
        $host,
        $port,
        $exception->getMessage()
    ));

    exit(0);
}

$statement = $pdo->query('select schema_name from information_schema.schemata order by schema_name');

if ($statement === false) {
    fwrite(STDERR, 'clean-engine: could not read information_schema.schemata.'.PHP_EOL);

    exit(1);
}

$names = [];

foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
    if (is_string($name)) {
        $names[] = $name;
    }
}

$reserved = ReservedDatabaseName::reservedAmong($names);

foreach ($reserved as $name) {
    if (! str_starts_with($name, CLEAN_ENGINE_PREFIX_LITERAL)) {
        fwrite(STDERR, 'clean-engine: the name guard called "'.$name.'" a reserved test database, but it '
            .'does not start with "'.CLEAN_ENGINE_PREFIX_LITERAL.'". The two prefix rules disagree, so the '
            .'guard class is wrong. Nothing here drops anything, but check '
            .'Flexipos\\Testing\\Engine\\ReservedDatabaseName before running the engine suite.'.PHP_EOL);

        exit(1);
    }
}

// Never silent when there is a leftover, because a leftover is evidence that a
// previous run was killed - and silent when there is not, so the gate's output
// stays readable.
if ($reserved !== []) {
    fwrite(STDOUT, 'clean-engine: '.count($reserved).' leftover reserved test database(s) on the engine: '
        .implode(', ', $reserved).'. This tool drops nothing (no sweep, ever). Each is dropped by name by '
        .'EngineDatabase::recreate() when a test next uses it, so this is information and not a failure.'
        .PHP_EOL);
}

exit(0);

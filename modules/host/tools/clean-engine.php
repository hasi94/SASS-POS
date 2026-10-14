<?php

declare(strict_types=1);

use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\ReservedDatabaseName;

/*
|--------------------------------------------------------------------------
| Drop the reserved test databases a killed run left behind
|--------------------------------------------------------------------------
|
| The engine suite creates databases. A run killed part-way - a Composer process
| timeout, Ctrl-C, a CI step limit - executes no PHP teardown at all, so the
| databases survive. The next run then migrates into a database that already
| holds tables, and the failure reads like a schema defect rather than a
| leftover.
|
| This is the same argument that puts tools/clean-probes.php first in the `check`
| script, and it is the measured one: `check` runs lint, stan, deptrac and only
| then test, so nothing driven from a test hook ever gets to run ahead of the
| steps a leftover breaks. Cleanup that must survive a killed process cannot
| live in an afterEach.
|
| Two deliberate choices:
|
| - **It enumerates `information_schema.schemata` and filters in PHP.** SQL LIKE
|   is forbidden here for the reason measured on this engine: `_` is a
|   single-character wildcard, so `SHOW DATABASES LIKE 'flexipos_%'` returns
|   `flexipos-master` and `flexipos-master-new`, two of the owner's real
|   databases. ReservedDatabaseNameGuardTest pins that expansion as a fact about
|   the engine.
| - **It is a bare PDO, not a framework connection.** It has to work when the
|   application cannot boot, and the only statements it issues are DDL against
|   names the guard has already allowed. That is also why it must never write a
|   row: on this server `@@sql_mode` carries no strict mode, and only the
|   framework's connector sets one per session (AC-68). The environment contract
|   itself is still read from EngineConnection::stanza(), so the variable names
|   have exactly one home.
|
| An unreachable engine is a warning here and not a failure: there is nothing to
| clean, and the engine tests themselves fail with the engine named (AC-63), so
| the gate still reports it where a reader can act on it.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
 * Three circuit breakers, added after the sweep in this exact shape dropped
 * sixteen of the owner's databases on 2026-10-14:
 *
 * - the suite-wide hold is honoured here too, so nothing in the repository
 *   touches that engine while the owner is deciding about recovery;
 * - every name is re-checked against a literal declared in this file, so the
 *   guard class and this script have to be wrong together;
 * - a ceiling on how many databases a sweep may believe are ours. The incident
 *   began with a filter that returned every schema on the engine. A sweep that
 *   thinks it owns dozens of databases is not having a busy day, it is broken.
 */
const CLEAN_ENGINE_PREFIX_LITERAL = 'flexipos_t8test_';
const CLEAN_ENGINE_MAX_SWEEP = 32;

if (EngineConnection::onHold()) {
    fwrite(STDOUT, 'clean-engine: the engine suite is on hold (ENGINE_HOLD), so nothing was swept and '
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
        'clean-engine: the engine at %s:%s is unreachable, so no reserved test database could be swept. '
        .'The engine tests will fail with the engine named. Underlying error: %s'.PHP_EOL,
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

$survivors = [];
$dropped = [];
$reserved = ReservedDatabaseName::reservedAmong($names);

if (count($reserved) > CLEAN_ENGINE_MAX_SWEEP) {
    fwrite(STDERR, sprintf(
        'clean-engine: the name filter returned %d of the engine\'s %d databases as reserved test '
        ."databases, which is more than the ceiling of %d. That is the shape of a broken filter, so\n"
        .'nothing was dropped. Check Flexipos\Testing\Engine\ReservedDatabaseName before running again.'
        .PHP_EOL,
        count($reserved),
        count($names),
        CLEAN_ENGINE_MAX_SWEEP
    ));

    exit(1);
}

foreach ($reserved as $name) {
    // Checked again at the point of effect, after the filter, by two
    // independent rules: the filter deciding and the statement running are two
    // different places, and only the second one is safe to trust.
    ReservedDatabaseName::assert($name);

    if (! str_starts_with($name, CLEAN_ENGINE_PREFIX_LITERAL)) {
        fwrite(STDERR, 'clean-engine: the name guard allowed "'.$name.'", which does not start with "'
            .CLEAN_ENGINE_PREFIX_LITERAL.'". The two checks disagree, so nothing was dropped.'.PHP_EOL);

        exit(1);
    }

    try {
        $pdo->exec('drop database if exists `'.$name.'`');
        $dropped[] = $name;
    } catch (Throwable $exception) {
        $survivors[] = $name.' ('.$exception->getMessage().')';
    }
}

if ($survivors !== []) {
    fwrite(
        STDERR,
        'clean-engine: a reserved test database survived the sweep. The next engine run would migrate '.
        'into a database that already holds tables, so drop it by hand:'.PHP_EOL.
        '  '.implode(PHP_EOL.'  ', $survivors).PHP_EOL
    );

    exit(1);
}

// Silent unless something was actually dropped, so the gate's output stays
// readable - but never silent when it was, because a leftover is evidence that
// a previous run was killed.
if ($dropped !== []) {
    fwrite(STDOUT, 'clean-engine: dropped '.count($dropped).' leftover reserved test database(s): '
        .implode(', ', $dropped).PHP_EOL);
}

exit(0);

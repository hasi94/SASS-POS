<?php

use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineDatabase;
use Flexipos\Testing\Engine\EngineOnHold;
use Flexipos\Testing\Engine\ReservedDatabaseName;
use Flexipos\Testing\Engine\ReservedDatabaseNameViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The guard that makes the rest of T-008 safe to write
|--------------------------------------------------------------------------
|
| This suite creates and drops databases on an engine that holds other
| projects' data, none of it backed up. Dropping one of those databases is the
| only irreversible act available anywhere in this task.
|
| It is not a hypothetical. On 2026-10-14, while proving this very test red, a
| deliberately permissive stub of ReservedDatabaseName was handed to an
| engine-wide sweep and sixteen of the owner's databases were dropped in
| alphabetical order, including dayananda_hidetest, demo_pos and flexhms_glow.
| Three things in this file exist because of that hour and are not negotiable:
|
| - **No test here sweeps the engine, and no code in this repository does.** A
|   test drops only names it created itself. EngineDatabase has no sweep, and
|   tools/clean-engine.php is report-only: it lists leftover reserved databases
|   ahead of the suite, drops nothing and exits 0. The capability was deleted
|   rather than bounded, because the ceiling it used to carry is a guess.
| - **Point-of-effect refusal is proven on a sqlite connection**, not on the
|   real engine. A control that hands a forbidden name to a live MySQL
|   connection is the accident it is testing for: during the incident, exactly
|   that control created flexipos_master on the real server.
| - **The filter is tested against the recorded, real list of this engine's
|   schemas** (guardEngineSnapshot below). That is the input the stub was given,
|   so it is the input the rule has to be proven on - offline, with no engine in
|   the loop at all.
|
| The remaining hazard the design was always meant to pin is the one that made
| the sweep lethal in the first place. MEASURED on this engine, 2026-10-14:
|
|     SHOW DATABASES LIKE 'flexipos_%'   ->  flexipos-master, flexipos-master-new
|     SHOW DATABASES LIKE 'flexipos\_%'  ->  (nothing)
|
| `_` is a single-character wildcard in SQL LIKE, so "list the test databases
| with LIKE, drop each one" drops two of the owner's real databases. The engine
| test below asserts that expansion as a FACT ABOUT THE ENGINE, so a later
| reader who finds LIKE tidier breaks a test instead of a client's data.
|
| Covers AC-61, and the fail-not-skip half of AC-63 (which is about an engine
| that is unreachable; the ENGINE_HOLD suspension below is a different and
| declared condition - see EngineOnHold).
|
*/

/**
 * Names that must be refused.
 *
 * The owner's near-collisions, two FlexiPOS tenant-shaped names, three real
 * client databases on this machine, a system schema, and four shapes that are
 * malformed rather than merely foreign. Every one of these is checked against
 * the *string* guard only: no test in this file hands a name like `pms` to
 * anything that can issue a statement.
 *
 * @return array<string, string>
 */
function guardRefusedNames(): array
{
    return [
        'the master database, underscore form' => 'flexipos_master',
        'the real master database' => 'flexipos-master',
        'the second real master database' => 'flexipos-master-new',
        'a tenant-shaped name' => 'flx_t_000001',
        'a second tenant-shaped name' => 'flx_t_000002',
        'a client database' => 'pms',
        'a second client database' => 'demo_pos',
        'a third client database' => 'flexhms_glow',
        'a system schema' => 'mysql',
        'the empty string' => '',
        'the reserved prefix in uppercase' => 'FLEXIPOS_T8TEST_X',
        'a traversal attempt inside the reserved prefix' => 'flexipos_t8test_../x',
        'a 70-character name inside the reserved prefix' => ReservedDatabaseName::PREFIX.str_repeat('a', 70 - strlen(ReservedDatabaseName::PREFIX)),
    ];
}

/**
 * Names that must be allowed. Without these, `return false` passes everything
 * above.
 *
 * @return array<string, string>
 */
function guardAllowedNames(): array
{
    return [
        'the shortest reserved name' => 'flexipos_t8test_a',
        'the reserved master' => 'flexipos_t8test_master',
        'a reserved tenant' => 'flexipos_t8test_tenant_a',
    ];
}

/**
 * Every schema this engine held on 2026-10-14, before the incident.
 *
 * Recorded rather than queried, for three reasons: it is the exact input the
 * permissive stub was given, it includes the sixteen databases that no longer
 * exist and can therefore never be re-measured, and a test that reads the live
 * list proves less every time the machine changes.
 *
 * @return list<string>
 */
function guardEngineSnapshot(): array
{
    return [
        '99mobility', '99mobility_new', '99mobility_test', 'ability', 'ability_dev', 'ability_poh',
        'ai_vehicle_research', 'autofill', 'dayananda', 'dayananda_hidetest', 'def', 'delivery',
        'delivery2', 'demo_pos', 'dulari_test', 'flexhms_glow', 'flexhms_j-dream-resort',
        'flexhms_master', 'flexihms_brown-bean', 'flexihms_forest-facelodge', 'flexipos-master',
        'flexipos-master-new', 'hesakibookshop', 'information_schema', 'kokobeach_20260930',
        'kokobeach_20261006', 'kokobeach_20261012', 'leadcheck', 'livemspos', 'master-old',
        'master_audit_1009', 'master_live_1005', 'master_t033', 'mysql', 'ov08', 'ovh', 'ovtest',
        'performance_schema', 'phpmyadmin', 'pms', 'pms_dev', 'renutextile', 'scriber',
        'scriber_testing', 'scriber_testing_t032', 'test', 'untouched_1040', 'untouched_1738',
        'untouched_2338', 'untouched_2338_as14', 'u_1012x1138', 'u_1012x2338', 'u_1013x0838',
        'u_1013x1139', 'u_1013x1339', 'u_1013x1539',
    ];
}

/**
 * The first column of each row of a `SHOW` result, whose column name depends on
 * the pattern that was matched.
 *
 * @param  array<int, mixed>  $rows
 * @return list<string>
 */
function guardFirstColumn(array $rows): array
{
    $values = [];

    foreach ($rows as $row) {
        foreach ((array) $row as $value) {
            if (is_scalar($value)) {
                $values[] = (string) $value;
            }

            break;
        }
    }

    return $values;
}

/**
 * Occurrences of SQL `LIKE` in *code*, with comments and docblocks removed.
 *
 * Tokenised rather than grepped: the sources scanned below discuss SQL LIKE at
 * length in their docblocks, and on this project a literal search has already
 * been defeated twice - once by a line wrap, once by a bold marker. The rule is
 * about code, so the assertion is about code.
 *
 * @return list<string>
 */
function guardLikeUsagesInCode(string $source): array
{
    $found = [];

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
            continue;
        }

        if (preg_match('/\blike\b/i', (string) $token[1]) === 1) {
            $found[] = trim((string) $token[1]);
        }
    }

    return $found;
}

/**
 * The test-support sources AC-61's source-scan control applies to.
 *
 * @return array<string, string>
 */
function guardEnumerationSources(): array
{
    $repository = dirname(base_path(), 2);

    $paths = [
        $repository.'/packages/testing/src/Engine/EngineDatabase.php',
        $repository.'/packages/testing/src/Engine/EngineConnection.php',
        $repository.'/packages/testing/src/Engine/ReservedDatabaseName.php',
        $repository.'/packages/testing/src/Engine/ReservedDatabaseNameViolation.php',
        $repository.'/modules/host/tools/clean-engine.php',
    ];

    $sources = [];

    foreach ($paths as $path) {
        expect($path)->toBeFile();

        $sources[basename($path)] = (string) file_get_contents($path);
    }

    return $sources;
}

/*
|--------------------------------------------------------------------------
| The rule itself: no engine in the loop
|--------------------------------------------------------------------------
*/

it('refuses every database name outside the reserved space', function (string $name) {
    expect(static fn () => ReservedDatabaseName::assert($name))
        ->toThrow(ReservedDatabaseNameViolation::class)
        ->and(ReservedDatabaseName::isReserved($name))->toBeFalse();
})->with(guardRefusedNames());

it('allows a reserved database name', function (string $name) {
    ReservedDatabaseName::assert($name);

    expect(ReservedDatabaseName::isReserved($name))->toBeTrue();
})->with(guardAllowedNames());

it('names the refused database and the rule in the message', function () {
    expect(static fn () => ReservedDatabaseName::assert('pms'))
        ->toThrow(ReservedDatabaseNameViolation::class, 'pms');
});

it('returns exactly the reserved name from a mixed fixture list', function () {
    $swept = ReservedDatabaseName::reservedAmong([
        'flexipos_t8test_a',
        'flexipos-master',
        'pms',
    ]);

    // Exact equality, not toContain: a filter that returned all three would
    // satisfy a containment assertion and cost the owner two databases.
    expect($swept)->toBe(['flexipos_t8test_a']);
});

it('claims none of the engine\'s real databases, on the recorded list that broke it', function () {
    // The regression test for the 2026-10-14 incident, run offline. The stub
    // returned all 56 of these; the rule must return none, because not one of
    // them carries the reserved prefix.
    expect(guardEngineSnapshot())->toHaveCount(56)
        ->and(ReservedDatabaseName::reservedAmong(guardEngineSnapshot()))->toBe([]);
});

it('finds the reserved names in a list that also holds the engine\'s real ones', function () {
    // The positive half of the test above: a filter hard-wired to return
    // nothing would pass it, and would also make every teardown a no-op.
    $names = array_merge(
        guardEngineSnapshot(),
        ['flexipos_t8test_a', 'flexipos_t8test_master']
    );

    expect(ReservedDatabaseName::reservedAmong($names))
        ->toBe(['flexipos_t8test_a', 'flexipos_t8test_master']);
});

/*
|--------------------------------------------------------------------------
| Refusal at the point of effect, proven without the real engine
|--------------------------------------------------------------------------
|
| EngineDatabase is handed the suite's sqlite connection. A refused name must
| throw before any statement is issued; a reserved name must reach the statement
| and be rejected by sqlite, which has no CREATE DATABASE at all. That second
| half is what proves the first one is a guard and not a dead method.
|
*/

it('refuses a foreign name at the point of effect, before any statement', function (string $name) {
    $engine = new EngineDatabase(DB::connection('sqlite'));

    expect(static fn () => $engine->create($name))->toThrow(ReservedDatabaseNameViolation::class)
        ->and(static fn () => $engine->drop($name))->toThrow(ReservedDatabaseNameViolation::class);
})->with(guardRefusedNames());

it('lets a reserved name through to the statement', function () {
    $engine = new EngineDatabase(DB::connection('sqlite'));

    // sqlite has no CREATE DATABASE, so reaching the driver at all is the
    // observable. A guard that refused everything would throw
    // ReservedDatabaseNameViolation here instead.
    expect(static fn () => $engine->create('flexipos_t8test_a'))->toThrow(QueryException::class);
});

it('issues no SQL LIKE in the code that enumerates, creates or drops a database', function () {
    $usages = [];

    foreach (guardEnumerationSources() as $file => $source) {
        $usages[$file] = guardLikeUsagesInCode($source);
    }

    // Compared as one map rather than file by file, so a failure names the file
    // it found it in and not only the count.
    expect($usages)->toBe(array_map(static fn (): array => [], $usages));
});

it('reports a LIKE in code and ignores one in a comment', function () {
    // The control for the scan above, as a fixture string rather than a file: it
    // needs no cleanup, so a killed run cannot leave it behind.
    $fixture = <<<'PHP'
        <?php
        // show databases like 'flexipos_%' - mentioned in a comment only
        /** show databases like 'flexipos_%' in a docblock */
        $rows = $connection->select("show databases like 'flexipos_%'");
        PHP;

    $found = guardLikeUsagesInCode($fixture);

    expect($found)->toHaveCount(1)
        ->and($found[0])->toBe('"show databases like \'flexipos_%\'"');
});

/*
|--------------------------------------------------------------------------
| The suspension, and the engine facts waiting behind it
|--------------------------------------------------------------------------
*/

it('opens no connection at all while the engine suite is on hold', function () {
    expect(static fn () => EngineConnection::connection())->toThrow(EngineOnHold::class)
        ->and(static fn () => EngineDatabase::engine())->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('pins the LIKE wildcard trap as a fact about this engine', function () {
    $connection = EngineConnection::connection();

    // The wrong enumeration, written the obvious way. Read-only: a SHOW, never
    // a DROP, and the only place in this task allowed to say LIKE at all.
    $unescaped = guardFirstColumn($connection->select("show databases like 'flexipos_%'"));

    expect($unescaped)->toContain('flexipos-master');

    // The same statement with the underscore escaped: the real databases
    // disappear, which is what makes the line above a wildcard expansion rather
    // than a coincidence of naming. PHP single quotes, so the backslash reaches
    // the server.
    $escaped = guardFirstColumn($connection->select('show databases like \'flexipos\\_%\''));

    expect($escaped)->not->toContain('flexipos-master');

    // And the rule that replaces it, over the same engine: enumerate from the
    // catalogue, filter in PHP.
    $engine = new EngineDatabase($connection);

    expect($engine->allNames())->toContain('flexipos-master')
        ->and($engine->reservedNames())->not->toContain('flexipos-master');
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('creates and drops its own reserved database and leaves every other schema alone', function () {
    $engine = EngineDatabase::engine();
    $name = 'flexipos_t8test_guard';

    // Every schema except this test's own, so the comparison is about the
    // databases that are not ours and says nothing about leftovers.
    $others = static fn (): array => array_values(array_filter(
        $engine->allNames(),
        static fn (string $schema): bool => $schema !== $name
    ));

    $before = $others();

    try {
        $engine->recreate($name);

        expect($engine->exists($name))->toBeTrue()
            ->and($engine->reservedNames())->toContain($name);

        // A failure between the create and the teardown: AC-61 requires nothing
        // to be left behind *including after a test that fails*, and a throw is
        // the only honest input for that.
        throw new RuntimeException('deliberate failure between create and teardown');
    } catch (RuntimeException) {
        // Only this test's own name, never a sweep of the engine.
        $engine->drop($name);
    }

    expect($others())->toBe($before)
        ->and($engine->exists($name))->toBeFalse();
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

it('writes the engine version into the test output', function () {
    expect(EngineConnection::version())->toContain('MariaDB');
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');

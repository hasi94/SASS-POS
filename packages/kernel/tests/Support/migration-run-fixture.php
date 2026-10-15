<?php

use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/master-mapping-fixture.php';

/*
 * T-008 steps 9 and 10: the master-side dataset the runner is planned against.
 *
 * A plain file, required by each test that needs it, for the reason
 * packages/kernel/tests/Support/tenancy-fixture.php exists: a bare function
 * declared in whichever test file was collected first makes a single-file run
 * fail and a full run pass (FP-164).
 *
 * **Nothing here opens a tenant connection, and that is the point of the whole
 * step.** Planning is master-only by definition - AC-39 and AC-59 are exactly
 * the claim that no tenant database is touched - so the fixture is three
 * inserts into the suite SQLite master, and runFixtureConnectionsUsedBy()
 * below is the instrument that makes "no tenant connection" an assertion
 * instead of a belief. The engine at 127.0.0.1:3306 is on hold (G-1) and is
 * not reachable from any test in this file.
 */

/**
 * A migration set made of the shipped baseline and zero or more FX
 * directories, in the order given.
 *
 * The directories are absolute and the shipped one is read from
 * `schema.tenant_migration_path`, which is its one authority. A version is a
 * position in the set, so appending FX1 gives it the version after the
 * baseline's last and leaves the baseline's numbering alone.
 */
function runFixtureSet(int ...$fx): MigrationSet
{
    $set = MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')));

    foreach ($fx as $number) {
        $set = $set->with('fx'.$number, base_path('tests/Fixtures/TenantMigrations/FX'.$number));
    }

    return $set;
}

/**
 * Publish a set's catalogue into `schema_versions` on the master.
 *
 * The runner refuses to start against an unpublished migration (AC-32), so
 * every test that expects a plan to be *written* has to publish first. It is
 * written here from the set rather than through `schema:publish`, because that
 * command publishes one tree and a set is several - and because a test that
 * needs an unpublished migration gets one by not calling this.
 */
function runFixturePublish(MigrationSet $set): void
{
    DB::connection('master')->table('schema_versions')->insert(
        array_map(static fn (MigrationCatalogue $entry): array => $entry->toRow(), $set->catalogue())
    );
}

/**
 * One mapped tenant, with the three fields the planner selects on.
 *
 * Returns the tenant id. `size_bytes` is always given a value, because AC-40
 * orders by it and a column left null would order by nothing.
 */
function runFixtureTenant(string $stem, string $serverId, string $status, int $schemaVersion, int $sizeBytes): string
{
    return masterMappingDatabase($stem, $serverId, [
        'status' => $status,
        'schema_version' => $schemaVersion,
        'size_bytes' => $sizeBytes,
    ]);
}

/**
 * Every `tenant_migration_jobs` row, in insertion order.
 *
 * @return list<array<string, mixed>>
 */
function runFixtureJobRows(): array
{
    $rows = [];

    foreach (DB::connection('master')->table('tenant_migration_jobs')->get() as $row) {
        $rows[] = (array) $row;
    }

    return $rows;
}

/**
 * Every `tenant_migration_runs` row.
 *
 * @return list<array<string, mixed>>
 */
function runFixtureRunRows(): array
{
    $rows = [];

    foreach (DB::connection('master')->table('tenant_migration_runs')->get() as $row) {
        $rows[] = (array) $row;
    }

    return $rows;
}

/**
 * The connections a piece of work issued a statement on, and the connections
 * the application resolved while it ran.
 *
 * This is the instrument behind "zero tenant connections were opened". Two
 * readings, because they fail differently: the query log names a connection
 * that was *used*, and the resolved list names one that was *built* - a
 * handler that constructed a tenant connection and then threw would be invisible
 * to the first and caught by the second.
 *
 * @template T
 *
 * `sql` is every statement in order, which is what AC-48's "the mutation that
 * grants the claim is a **single** statement" is counted from.
 *
 * @param  Closure(): T  $work
 * @return array{result: T|null, thrown: Throwable|null, queried: list<string>, resolved: list<string>, sql: list<string>}
 */
function runFixtureConnectionsUsedBy(Closure $work): array
{
    $queried = [];
    $sql = [];

    DB::listen(static function ($query) use (&$queried, &$sql): void {
        $queried[] = (string) $query->connectionName;
        $sql[] = (string) $query->sql;
    });

    $result = null;
    $thrown = null;

    try {
        $result = $work();
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // Stop listening: Pest keeps one application per test, but a listener that
    // outlived its test would record another test statements as this one.
    Event::forget('Illuminate\Database\Events\QueryExecuted');

    $resolved = array_map(
        static fn ($name): string => (string) $name,
        array_keys(DB::getConnections())
    );

    sort($resolved);

    return [
        'result' => $result,
        'thrown' => $thrown,
        'queried' => array_values(array_unique($queried)),
        'resolved' => $resolved,
        'sql' => $sql,
    ];
}

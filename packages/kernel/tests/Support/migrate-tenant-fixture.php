<?php

use Flexipos\Kernel\Schema\Exceptions\TenantMigrationFailed;
use Flexipos\Kernel\Schema\Features\MigrateTenant\TenantMigrationApplier;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/migration-run-fixture.php';
require_once __DIR__.'/tenancy-fixture.php';

/*
 * T-008 step 11: the execution path's dataset, on the stand-in.
 *
 * A plain file required by each test that needs it, for the reason
 * packages/kernel/tests/Support/tenancy-fixture.php exists (FP-164).
 *
 * **Two tenants, each a real SQLite file, and no engine anywhere.** The engine
 * at 127.0.0.1:3306 is on hold (G-1) and nothing in this file can reach it:
 * the master is the suite in-memory SQLite, and each tenant database is a
 * throwaway SQLite file under storage/. That is what makes the half of step 11
 * that does not need a real engine - the status transitions, the mirror write,
 * the cleared error, the eviction and the error sanitising - provable while
 * the hold is on. The engine half (FX-1's table existing in A, B and C) is
 * [gated] and lives in modules/host/tests/Engine/.
 *
 * The connection is still built the only way the platform ever builds one:
 * Tenancy::enter() for the Migrating purpose, through
 * TenantConnectionAuthority. SQLite is in TENANCY_ALLOWED_DRIVERS for exactly
 * this reason, and `database_servers.engine` is what the mapping reader turns
 * into the stanza's driver.
 *
 * The migrator itself is faked, through the TenantMigrationApplier seam - see
 * that interface for why. The fake is a stand-in for the framework's migrator
 * and not a stub of a guard: it records what it was asked to apply, refuses to
 * apply the same migration twice to the same database, and throws when a case
 * tells it to. Nothing in this file weakens a refusal to make a test fail.
 */

/**
 * A migrator that records rather than migrates.
 *
 * Per **database name**, never per call, because AC-42 and AC-44 are claims
 * about two tenants in one process: state kept per call would let the second
 * tenant inherit the first one's applied list and every "the other tenants
 * still completed" assertion would be measuring one database twice.
 */
final class FakeTenantMigrationApplier implements TenantMigrationApplier
{
    /**
     * The one this test is using.
     *
     * A static handle rather than a property on the test case: Pest's test
     * object is a PHPUnit TestCase and an ad-hoc property on it has no
     * declared type, so every read of it is a static-analysis error. Reset by
     * migrateFixtureBoot() in each beforeEach, which is what keeps it from
     * being state one case inherits from another.
     */
    private static ?self $current = null;

    /** @var list<array{database: string, target: int, set: string}> */
    public array $calls = [];

    /** @var array<string, list<string>> database name => migrations it carries */
    public array $appliedByDatabase = [];

    /**
     * Called once per migration this fake is about to apply, with the database
     * name and the migration name. Throw from it to make that migration fail
     * on that tenant; it is how FX-2's "fails on tenant C only" is expressed
     * without an engine.
     *
     * @var Closure(string, string): void|null
     */
    public ?Closure $onMigration = null;

    /**
     * Apply at most this many migrations per call and then return
     * successfully - a stand-in for a migrator that applied part of the tree
     * without failing. It is the input that tells a mirror which is a
     * **reading** of the tenant from one which assumes the job's target was
     * reached.
     */
    public ?int $applyAtMost = null;

    /**
     * The fake the current test is using.
     */
    public static function current(): self
    {
        if (! self::$current instanceof self) {
            throw new RuntimeException('migrateFixtureBoot() has not run for this test.');
        }

        return self::$current;
    }

    /**
     * Make this the fake the current test is using.
     */
    public static function useThis(self $applier): void
    {
        self::$current = $applier;
    }

    public function apply(string $connection, MigrationSet $set, int $target): array
    {
        $database = $this->databaseNameOf($connection);

        $this->calls[] = ['database' => $database, 'target' => $target, 'set' => $set->name];

        $applied = [];

        foreach ($set->catalogue() as $entry) {
            if ($entry->version > $target || in_array($entry->migration, $this->appliedMigrations($connection), true)) {
                continue;
            }

            if ($this->applyAtMost !== null && count($applied) >= $this->applyAtMost) {
                break;
            }

            if ($this->onMigration instanceof Closure) {
                try {
                    ($this->onMigration)($database, $entry->migration);
                } catch (Throwable $cause) {
                    // Wrapped exactly as the shipped applier wraps: the seam's
                    // contract is that a failure names the migration, and a
                    // fake that let the raw cause out would make the handler
                    // look as though it could not fill error_migration.
                    throw TenantMigrationFailed::whileApplying($entry->migration, $cause);
                }
            }

            $this->appliedByDatabase[$database][] = $entry->migration;
            $applied[] = $entry->migration;
        }

        return $applied;
    }

    public function appliedMigrations(string $connection): array
    {
        return $this->appliedByDatabase[$this->databaseNameOf($connection)] ?? [];
    }

    /**
     * Pretend one database already carries these migrations - the input AC-46
     * needs, where a tenant that is already `done` must not be migrated twice.
     *
     * @param  list<string>  $migrations
     */
    public function pretendApplied(string $database, array $migrations): void
    {
        $this->appliedByDatabase[$database] = $migrations;
    }

    /**
     * The database the named connection is pointed at.
     *
     * Read from the live connection rather than from the mapping, because that
     * is AC-42's own observable: "each job reports the database name its
     * connection is using".
     */
    private function databaseNameOf(string $connection): string
    {
        return (string) DB::connection($connection)->getDatabaseName();
    }
}

/**
 * Where the throwaway tenant SQLite files live.
 */
function migrateFixtureRoot(): string
{
    static $root = null;

    if ($root === null) {
        $root = storage_path('app'.DIRECTORY_SEPARATOR.'migrate-tenant-fixture');
    }

    return $root;
}

function migrateFixtureDatabasePath(string $stem): string
{
    return migrateFixtureRoot().DIRECTORY_SEPARATOR.$stem.'.sqlite';
}

/**
 * The one distinctive credential every leak assertion in step 11 looks for.
 *
 * Distinctive on purpose: a password of "secret" would be found inside half
 * the words in an error message and a redaction assertion would pass on an
 * accident.
 */
function migrateFixturePassword(): string
{
    return 'm1grate-DO-NOT-LEAK-91bc';
}

/**
 * The credential reference the fixture mappings carry.
 */
function migrateFixtureCredentialRef(): string
{
    return 'env://MIGRATE_FIXTURE_PASSWORD';
}

/**
 * Boot the stand-in: the master schema, the credential, the fake applier and
 * a stopped clock.
 *
 * The mapping source is switched to `master`, because step 11's writes are
 * master writes and D-19's eviction is a claim about the **caching decorator
 * over the master reader** - the shipped graph. The suite's default is the
 * in-memory source (phpunit.xml, ruling 13.4) and a test that left it there
 * would be asserting eviction on a reader that caches nothing.
 */
function migrateFixtureBoot(): FakeTenantMigrationApplier
{
    masterMappingMigrate();

    File::ensureDirectoryExists(migrateFixtureRoot());

    tenancyWriteEnvironment('MIGRATE_FIXTURE_PASSWORD', migrateFixturePassword());

    config()->set('tenancy.mapping.source', 'master');
    config()->set('tenancy.mapping.ttl', 300);
    config()->set('schema.max_attempts', 3);
    config()->set('schema.claim_ttl_minutes', 30);
    config()->set('schema.per_server_limit', 2);

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-15 09:00:00.000'));

    $applier = new FakeTenantMigrationApplier;

    FakeTenantMigrationApplier::useThis($applier);

    app()->instance(TenantMigrationApplier::class, $applier);

    return $applier;
}

/**
 * The fake migrator this test is using.
 */
function migrateFixtureApplier(): FakeTenantMigrationApplier
{
    return FakeTenantMigrationApplier::current();
}

/**
 * The identifier masterMappingServer()/masterMappingDatabase() give a stem.
 *
 * Read through the same helper that writes it, so a test never spells a
 * padded identifier out and the two can never disagree.
 */
function migrateFixtureId(string $stem): string
{
    return masterMappingId($stem);
}

/**
 * Remove the throwaway tenant databases. Safe to call when there are none.
 */
function migrateFixtureTearDown(): void
{
    DB::purge('tenant');

    File::deleteDirectory(migrateFixtureRoot());
}

/**
 * One `database_servers` row whose engine is the stand-in driver.
 *
 * `engine` is what the mapping reader turns into the connection stanza's
 * driver, so this is the single place the fixture says "SQLite" - the handler
 * under test names no driver at all.
 */
function migrateFixtureServer(string $stem = 'server-one'): string
{
    return masterMappingServer($stem, ['engine' => 'sqlite', 'host' => '127.0.0.1', 'port' => 3306]);
}

/**
 * One mapped tenant, with a real SQLite file behind it.
 *
 * @param  array<string, mixed>  $overrides
 */
function migrateFixtureTenant(string $stem, string $serverId, array $overrides = []): string
{
    $path = migrateFixtureDatabasePath($stem);

    File::put($path, '');

    return masterMappingDatabase($stem, $serverId, array_merge([
        'database_name' => $path,
        'app_credential_ref' => migrateFixtureCredentialRef(),
        'migrate_credential_ref' => migrateFixtureCredentialRef(),
        'status' => 'active',
        'schema_version' => 0,
        'size_bytes' => 1024,
    ], $overrides));
}

/**
 * One job row in whatever state the case needs, claimed by a named worker.
 *
 * Written directly rather than planned and claimed, because this slice is
 * about executing a job that is already held: planning and claiming have
 * their own slices and their own tests, and building the row here is what
 * keeps a failure in those from reading as a failure in this one.
 *
 * @param  array<string, mixed>  $overrides
 */
function migrateFixtureClaimedJob(
    string $runId,
    string $jobId,
    string $tenantId,
    string $serverId,
    int $toVersion,
    string $worker = 'worker-one',
    array $overrides = [],
): string {
    DB::connection('master')->table('tenant_migration_jobs')->insert(array_merge([
        'id' => $jobId,
        'run_id' => $runId,
        'tenant_id' => $tenantId,
        'server_id' => $serverId,
        'from_version' => 0,
        'to_version' => $toVersion,
        'status' => 'running',
        'attempts' => 1,
        'claimed_by' => $worker,
        'claimed_at' => '2026-10-15 09:00:00.000',
        'lock_expires_at' => '2026-10-15 09:30:00.000',
        'started_at' => '2026-10-15 09:00:00.000',
    ], $overrides));

    return $jobId;
}

/**
 * The run every job in these fixtures belongs to, named once.
 */
function migrateFixtureRunId(): string
{
    return 'RUN0000000000000000000001';
}

/**
 * One run row for the jobs to belong to.
 */
function migrateFixtureRun(?string $runId = null, int $target = 6): string
{
    $runId ??= migrateFixtureRunId();

    DB::connection('master')->table('tenant_migration_runs')->insert([
        'id' => $runId,
        'target_version' => $target,
        'mode' => 'bulk',
        'concurrency' => 1,
        'status' => 'planned',
        'total' => 0,
        'pending' => 0,
        'created_at' => '2026-10-15 09:00:00.000',
        'updated_at' => '2026-10-15 09:00:00.000',
    ]);

    return $runId;
}

/**
 * One job row, as an array.
 *
 * @return array<string, mixed>
 */
function migrateFixtureJobRow(string $jobId): array
{
    return (array) DB::connection('master')->table('tenant_migration_jobs')->where('id', $jobId)->first();
}

/**
 * One mapping row, as an array.
 *
 * @return array<string, mixed>
 */
function migrateFixtureMappingRow(string $tenantId): array
{
    return (array) DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->first();
}

/**
 * One run row, as an array.
 *
 * @return array<string, mixed>
 */
function migrateFixtureRunRow(string $runId): array
{
    return (array) DB::connection('master')->table('tenant_migration_runs')->where('id', $runId)->first();
}

/**
 * Write a tenant's own `migrations` table, with the first `$upTo` migrations
 * of the shipped baseline in it.
 *
 * T-008 step 12. The tenant's own table is the **authority** on where it is
 * (D-14), so a drift or repair case has to be able to set it independently of
 * the mapping - that disagreement is the whole input. Built with a bare PDO
 * on purpose: going through the connection manager would be the machinery
 * under test writing its own input.
 */
function migrateFixtureApplyLog(string $stem, int $upTo): void
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));

    // One transaction, because SQLite commits - and fsyncs - every bare
    // statement on its own. MEASURED on this machine: eight autocommitted
    // statements per tenant cost about 0.9 s, which is the difference between
    // this slice's tests costing one second and costing nine.
    $pdo->beginTransaction();

    $pdo->exec('create table if not exists migrations (id integer primary key autoincrement, migration varchar(255) not null, batch integer not null)');
    $pdo->exec('delete from migrations');

    foreach (runFixtureSet()->catalogue() as $entry) {
        if ($entry->version > $upTo) {
            break;
        }

        $pdo->exec('insert into migrations (migration, batch) values ('.$pdo->quote($entry->migration).', 1)');
    }

    $pdo->commit();
}

/**
 * Every migration one tenant's own table lists, read the way a reviewer would.
 *
 * Returned as `migration => batch`, because AC-46 asks for row counts **and**
 * batch numbers: a re-applied migration appears under a second batch, and a
 * count alone would miss a tenant whose table was rewritten rather than
 * appended to.
 *
 * @return array<string, int>
 */
function migrateFixtureApplyLogOf(string $stem): array
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));

    $rows = $pdo->query('select migration, batch from migrations order by id');

    $log = [];

    foreach ($rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $log[(string) $row['migration']] = (int) $row['batch'];
    }

    return $log;
}

/**
 * Plant (or clear) the `schema_meta` identity row of one tenant database.
 *
 * AC-56's input, and the criterion says so: T-008 writes nothing into
 * `schema_meta`, so the only way a mismatch exists at all is a test that
 * plants one. Null clears the row and leaves the table, which is the third
 * state - a database that does not say who it is, and the shipped one.
 */
function migrateFixtureSchemaMeta(string $stem, ?string $tenantId): void
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));

    $pdo->beginTransaction();

    $pdo->exec('create table if not exists schema_meta (key varchar(60) primary key, value varchar(255))');
    $pdo->exec('delete from schema_meta');

    if ($tenantId !== null) {
        $pdo->exec('insert into schema_meta (key, value) values (\'tenant_id\', '.$pdo->quote($tenantId).')');
    }

    $pdo->commit();
}

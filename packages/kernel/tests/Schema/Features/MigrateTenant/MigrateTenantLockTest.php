<?php

use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenant;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantHandler;
use Flexipos\Kernel\Schema\Support\PerTenantLock;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| AC-49's wiring and its outcome: a locked tenant is not run
|--------------------------------------------------------------------------
|
| T-008 BUG-3. PerTenantLock was a correct class with a unit test, an engine
| test and no caller: two runners could enter one tenant and nothing in the
| branch could tell. qa's wiring check found the absence; this file is what
| the runner now does with the answer.
|
| **AC-49 and AC-65 stay NOT VERIFIED (ENGINE_HOLD, G-1) and nothing here
| changes that.** Whether a second session is really refused is a question
| for two sessions on a real engine, and GET_LOCK is re-entrant within one
| session, so no single process can answer it. What is asserted here is the
| handler's half: when the lock is refused, the tenant's database is not
| touched, no mapping is written, the job ends `skipped` rather than `failed`
| and the run carries on.
|
| The refusal is produced at the driver seam: a connection that is SQLite in
| every respect except that it answers `get_lock` with a planted value and
| reports a driver name that has advisory locks. Everything above that seam -
| the handler, the choke point, the job bookkeeping - is the shipped code, and
| the control beside each case is the same fixture with the lock granted.
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

/**
 * The engine name the lockable stand-in is registered under.
 */
const LOCKABLE_ENGINE = 'sqlite-lockable';

/**
 * What the planted server answers, and what it was asked.
 */
final class LockableServerState
{
    public bool $granted = true;

    /** @var list<string> */
    public array $statements = [];
}

/**
 * Register a SQLite connection that answers the two advisory-lock statements
 * itself and reports a driver that has them.
 *
 * It builds its own PDO rather than using the manager's resolver, because the
 * driver name it is registered under has no connector - which is the point: a
 * deployment cannot reach it, only this file can.
 */
function lockableServer(bool $granted): LockableServerState
{
    $state = new LockableServerState;
    $state->granted = $granted;

    Connection::resolverFor(LOCKABLE_ENGINE, function ($pdo, $database, $prefix, $config) use ($state): Connection {
        return new class(new PDO('sqlite:'.$database), $database, $prefix, $config, $state) extends SQLiteConnection
        {
            /**
             * @param  array<string, mixed>  $config
             */
            public function __construct(
                PDO $pdo,
                string $database,
                string $prefix,
                array $config,
                private readonly LockableServerState $state,
            ) {
                parent::__construct($pdo, $database, $prefix, $config);
            }

            public function getDriverName()
            {
                return 'mariadb';
            }

            /**
             * @param  array<int|string, mixed>  $bindings
             */
            public function selectOne($query, $bindings = [], $useReadPdo = true)
            {
                if (str_contains($query, 'get_lock')) {
                    $this->state->statements[] = $query;

                    return (object) ['granted' => $this->state->granted ? 1 : 0];
                }

                if (str_contains($query, 'release_lock')) {
                    $this->state->statements[] = $query;

                    return (object) ['released' => 1];
                }

                return parent::selectOne($query, $bindings, $useReadPdo);
            }
        };
    });

    return $state;
}

/**
 * The fixture, pointed at the lockable stand-in instead of plain SQLite.
 */
function lockableFixtureServer(): string
{
    return masterMappingServer('server-one', [
        'engine' => LOCKABLE_ENGINE,
        'host' => '127.0.0.1',
        'port' => 3306,
    ]);
}

beforeEach(function () {
    migrateFixtureBoot();
    migrateFixtureRun();

    config()->set('tenancy.allowed_drivers', ['sqlite', LOCKABLE_ENGINE]);
});

afterEach(function () {
    migrateFixtureTearDown();
});

it('migrates the tenant when the lock is granted, and gives it back', function () {
    // The control. Same fixture, same driver, one value different.
    $server = lockableServer(granted: true);
    $tenant = migrateFixtureTenant('tenant-a', lockableFixtureServer());
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), 6);

    $result = app(MigrateTenantHandler::class)->handle(new MigrateTenant($job, 'worker-one', runFixtureSet()));

    expect($result->succeeded)->toBeTrue()
        ->and($result->locked)->toBeFalse()
        ->and($result->applied)->toHaveCount(6)
        ->and((string) migrateFixtureJobRow($job)['status'])->toBe('done')
        // Taken and given back: a run that kept the lock would hold one
        // tenant against the next worker until the process exited.
        ->and(count($server->statements))->toBe(2)
        ->and($server->statements[0])->toContain('get_lock')
        ->and($server->statements[1])->toContain('release_lock');
});

it('does not run a tenant whose lock another session holds, and changes nothing in it', function () {
    lockableServer(granted: false);

    $tenant = migrateFixtureTenant('tenant-a', lockableFixtureServer(), [
        'schema_version' => 2,
        'last_migration_error' => 'from an earlier run',
    ]);
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), 6);

    $result = app(MigrateTenantHandler::class)->handle(new MigrateTenant($job, 'worker-one', runFixtureSet()));

    expect($result->locked)->toBeTrue()
        ->and($result->succeeded)->toBeFalse()
        ->and($result->applied)->toBe([])
        ->and($result->errorMessage)->toContain(PerTenantLock::nameFor($tenant))
        // Nothing was applied: the migrator was never called for this
        // database at all.
        ->and(migrateFixtureApplier()->calls)->toBe([]);

    $mapping = migrateFixtureMappingRow($tenant);

    // AC-49: nothing in the tenant's row changed either - not the status, not
    // the version, and not the error another worker may be managing.
    expect((int) $mapping['schema_version'])->toBe(2)
        ->and((string) $mapping['status'])->toBe('active')
        ->and((string) $mapping['last_migration_error'])->toBe('from an earlier run');

    $row = migrateFixtureJobRow($job);

    // `skipped`, not `failed`: a tenant somebody else is migrating is not an
    // error, and a run that ended completed_with_failures over one would have
    // an operator investigating a healthy platform.
    expect((string) $row['status'])->toBe('skipped')
        ->and((string) $row['error_message'])->toContain('not run')
        ->and($row['error_class'])->toBeNull();
});

it('leaves the other tenants of the run to complete', function () {
    lockableServer(granted: false);

    $server = lockableFixtureServer();
    $locked = migrateFixtureTenant('tenant-a', $server);
    $other = migrateFixtureTenant('tenant-b', masterMappingServer('server-two', [
        'engine' => 'sqlite',
        'host' => '127.0.0.1',
        'port' => 3306,
    ]));

    $lockedJob = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $locked, migrateFixtureId('server-one'), 6);
    $otherJob = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000002', $other, migrateFixtureId('server-two'), 6);

    $first = app(MigrateTenantHandler::class)->handle(new MigrateTenant($lockedJob, 'worker-one', runFixtureSet()));
    $second = app(MigrateTenantHandler::class)->handle(new MigrateTenant($otherJob, 'worker-one', runFixtureSet()));

    // The tenant behind the locked one is migrated by the same worker in the
    // same process: a refused lock is not an exception and does not end the
    // run.
    expect($first->locked)->toBeTrue()
        ->and($second->succeeded)->toBeTrue()
        ->and($second->schemaVersion)->toBe(6);
});

<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionFailed;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
 * QA proof for T-003 AC-4, AC-15, AC-16, AC-17 and AC-18.
 *
 * `dev` proved these too. This file reproduces them from its own fixture, with
 * its own helpers, because the interesting failure mode for a security boundary
 * is a harness that agrees with the code rather than with reality - and because
 * three of the shapes below are ones `dev`'s suite does not have: a connection
 * captured inside a closure, a transaction left open across a switch, a second
 * switch back to the first tenant, and a connection built through the
 * DatabaseManager API that does not consult the guard at all.
 *
 * Nothing in this file is expected to be red. (T-003 BUG-2, the build() and
 * connectUsing() bypass, was closed in T-003 and re-closed at its root in T-007:
 * the stanza checks moved from a name-keyed extension and an event listener into
 * the one factory decoration.)
 *
 * T-007 added the second half of the file: qa's own AC-23 table, written from the
 * spec's list of routes with its own helpers and its own stanzas (qa read dev's
 * route names, not its table), so the two tables agree about the connection
 * factory only by both being right about it.
 */

uses(TestCase::class);

/**
 * This file's own fixture root, deliberately separate from the one
 * TenancyResolutionTest creates, so neither file can mask a failure in the
 * other by leaving state behind.
 */
function qaIsoRoot(): string
{
    // Memoised because the afterAll hook below runs outside the application
    // lifecycle, where storage_path() does not exist. The value is a constant
    // for the life of the process, so the first beforeEach can resolve it.
    static $root = null;

    return $root ??= storage_path('app'.DIRECTORY_SEPARATOR.'qa-tenancy-isolation');
}

function qaIsoDatabasePath(string $tenant): string
{
    return qaIsoRoot().DIRECTORY_SEPARATOR.$tenant.'.sqlite';
}

function qaIsoPassword(): string
{
    return 'qa-iso-PASSWORD-must-not-leak-91b4';
}

/**
 * A SQLite file whose single `marker` row names the tenant it belongs to, so
 * "the query reached this tenant's database" is a fact about data.
 */
function qaIsoCreateDatabase(string $tenant): void
{
    $path = qaIsoDatabasePath($tenant);

    File::ensureDirectoryExists(dirname($path));
    File::delete($path);
    File::put($path, '');

    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('create table marker (value varchar(50) not null)');
    $pdo->exec('insert into marker (value) values ('.$pdo->quote($tenant).')');
}

function qaIsoWriteEnvironment(string $variable, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$variable], $_SERVER[$variable]);
        putenv($variable);

        return;
    }

    $_ENV[$variable] = $value;
    $_SERVER[$variable] = $value;
    putenv($variable.'='.$value);
}

/**
 * @return array<string, array<string, mixed>>
 */
function qaIsoMapping(): array
{
    $record = static fn (string $tenant): array => [
        'driver' => 'sqlite',
        'host' => 'qa-iso-'.$tenant,
        'port' => 3306,
        'database' => qaIsoDatabasePath($tenant),
        'username' => 'qa_iso_'.str_replace('-', '_', $tenant),
        'credential_ref' => 'env://QA_ISO_APP_PASSWORD',
        'status' => 'active',
    ];

    return [
        'qa-iso-a' => $record('qa-iso-a'),
        'qa-iso-b' => $record('qa-iso-b'),
    ];
}

function qaIsoBoot(): void
{
    // Once per file, not once per case (T-004 FP-155 did the same for the shared
    // fixture, and this file has well over a hundred cases). Safe because no case
    // writes to either database: the one DDL statement in this file runs inside a
    // transaction that is rolled back, and every other statement is a select.
    // The afterAll hook at the foot of the file removes the directory.
    foreach (['qa-iso-a', 'qa-iso-b'] as $tenant) {
        if (! is_file(qaIsoDatabasePath($tenant))) {
            qaIsoCreateDatabase($tenant);
        }
    }

    qaIsoWriteEnvironment('QA_ISO_APP_PASSWORD', qaIsoPassword());

    config()->set('tenancy.databases', qaIsoMapping());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();
}

function qaIsoForget(): void
{
    app()->forgetScopedInstances();
    app(Tenancy::class)->leave();

    qaIsoWriteEnvironment('QA_ISO_APP_PASSWORD', null);
}

function qaIso(): Tenancy
{
    return app(Tenancy::class);
}

/**
 * @return array<string, mixed>
 */
function qaIsoStanza(): array
{
    return (array) config('database.connections.tenant');
}

/**
 * @return array<string, mixed>
 */
function qaIsoPlaceholder(): array
{
    return ['driver' => 'flexipos-no-tenant', 'database' => '', 'prefix' => ''];
}

function qaIsoMarker(): string
{
    return (string) DB::connection('tenant')->scalar('select value from marker');
}

beforeEach(function () {
    qaIsoBoot();
});

afterEach(function () {
    qaIsoForget();
});

it('refuses a fully usable tenant stanza before any network attempt, when no tenant is resolved', function () {
    // AC-4's hardest reading. The at-rest placeholder is not the interesting
    // case: a sentinel driver cannot connect whatever the guard does. The
    // interesting case is a stanza that WOULD connect - one a queue worker left
    // behind, or one somebody hand-wrote - reached with no tenant resolved.
    //
    // The host is TEST-NET-3 (RFC 5737), which is guaranteed unroutable, so a
    // driver-level attempt would block on the TCP timeout for seconds. Refusing
    // in milliseconds is therefore evidence about *when* the refusal happens,
    // not merely that it happens.
    config()->set('database.connections.tenant', [
        'driver' => 'mysql',
        'host' => '203.0.113.7',
        'port' => 3306,
        'database' => 'qa_iso_unreachable',
        'username' => 'qa_iso',
        'password' => qaIsoPassword(),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'options' => [],
        // T-007 FP-161 (dev, declared): the stanza no longer carries a tenant
        // id. A hand-written one carries whatever its writer chose, and a
        // plausible authenticator of the right shape keeps this the hardest
        // case rather than the easiest - the refusal below has to be about the
        // missing tenant, not about a missing key.
        'flexipos_tenant_authenticator' => str_repeat('a', 32),
    ]);

    DB::purge('tenant');

    $established = [];

    Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$established): void {
        $established[] = (string) $event->connectionName;
    });

    $startedAt = microtime(true);
    $refusal = null;

    try {
        DB::connection('tenant')->scalar('select 1');
    } catch (Throwable $exception) {
        $refusal = $exception;
    }

    $elapsed = microtime(true) - $startedAt;

    expect($refusal)->toBeInstanceOf(NoTenantResolved::class)
        // A real connection attempt to an unroutable address cannot come back
        // this fast on any platform.
        ->and($elapsed)->toBeLessThan(1.0)
        ->and($established)->not->toContain('tenant')
        ->and(array_keys(DB::getConnections()))->not->toContain('tenant');
});

it('still reaches the driver when a tenant IS resolved, so the refusal above is about the tenant', function () {
    // The negative control for the case above. Without it, "it threw" proves
    // only that something was wrong, not that the *missing tenant* was what
    // stopped it before the driver ran.
    //
    // T-007 R6.1 (dev, declared): this control used to mutate
    // database.connections.tenant.database inside the scope. Under FP-161 the
    // stanza is bound to its own content, so that mutation IS the refusal and
    // the control would prove the opposite of what it exists to prove. The
    // unopenable database comes from the MAPPING instead, before entering,
    // which keeps the discrimination intact: the driver is what complains, not
    // the authenticator and not the missing tenant.
    $mapping = qaIsoMapping();
    $mapping['qa-iso-a']['database'] = qaIsoRoot().DIRECTORY_SEPARATOR.'no-such-dir'.DIRECTORY_SEPARATOR.'x.sqlite';

    config()->set('tenancy.databases', $mapping);
    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();

    $refusal = null;

    try {
        qaIso()->enter('qa-iso-a');
    } catch (Throwable $exception) {
        $refusal = $exception;
    }

    // It got as far as the driver: TenantConnectionFailed is the class Tenancy
    // raises only after an open attempt failed, and it is neither of the two
    // refusals the guard raises before one.
    expect($refusal)->toBeInstanceOf(TenantConnectionFailed::class)
        ->and($refusal)->not->toBeInstanceOf(TenantConnectionMismatch::class)
        ->and($refusal)->not->toBeInstanceOf(NoTenantResolved::class)
        // And the failed entry left nothing resolved behind it.
        ->and(qaIso()->current()->hasTenant())->toBeFalse();
});

it('refuses a tenant connection captured inside a closure across a tenant switch', function () {
    // The shape a deferred callback, an event listener or a `once()` memo
    // produces: nobody stored the connection on a property, it was simply
    // closed over.
    qaIso()->enter('qa-iso-a');

    $connection = DB::connection('tenant');
    $read = static fn (): string => (string) $connection->scalar('select value from marker');

    expect($read())->toBe('qa-iso-a');

    qaIso()->leave();
    qaIso()->enter('qa-iso-b');

    try {
        expect($read)->toThrow(TenantConnectionMismatch::class, 'built for tenant [qa-iso-a]')
            ->and(qaIsoMarker())->toBe('qa-iso-b');
    } finally {
        $connection->disconnect();
        qaIso()->leave();
    }
});

it('refuses a held connection while another tenant is resolved and serves the first again on the way back', function () {
    // A -> B -> A in one process. The middle leg must refuse; the third leg
    // must work, because refusing forever would be a different bug (a worker
    // that can serve each tenant only once).
    qaIso()->enter('qa-iso-a');
    $connection = DB::connection('tenant');

    expect((string) $connection->scalar('select value from marker'))->toBe('qa-iso-a');

    qaIso()->leave();
    qaIso()->enter('qa-iso-b');

    try {
        expect(fn (): mixed => $connection->scalar('select value from marker'))
            ->toThrow(TenantConnectionMismatch::class)
            ->and(qaIsoMarker())->toBe('qa-iso-b')
            ->and(qaIso()->current()->tenantId())->toBe('qa-iso-b');
    } finally {
        qaIso()->leave();
    }

    qaIso()->enter('qa-iso-a');

    try {
        expect(qaIsoMarker())->toBe('qa-iso-a')
            // And the object from the first A scope is usable again, pointed at
            // A - the same tenant it was built for, so the guard has nothing to
            // object to.
            ->and((string) $connection->scalar('select value from marker'))->toBe('qa-iso-a');
    } finally {
        $connection->disconnect();
        qaIso()->leave();
    }

    expect(qaIsoStanza())->toBe(qaIsoPlaceholder());
});

it('rolls back a transaction left open across a tenant switch and still serves the next tenant', function () {
    // Two shapes. First: the job remembered to leave, with a transaction still
    // open - leave() must roll back rather than throw, because it runs in a
    // finally.
    qaIso()->enter('qa-iso-a');

    $connection = DB::connection('tenant');
    $connection->beginTransaction();
    $connection->statement('create table qa_iso_scratch (v integer)');

    expect($connection->transactionLevel())->toBe(1);

    qaIso()->leave();

    expect($connection->transactionLevel())->toBe(0)
        ->and(qaIsoStanza())->toBe(qaIsoPlaceholder());

    qaIso()->enter('qa-iso-b');

    try {
        expect(qaIsoMarker())->toBe('qa-iso-b')
            // The rolled-back DDL did not reach tenant B's database either.
            ->and(fn (): mixed => DB::connection('tenant')->scalar('select count(*) from qa_iso_scratch'))
            ->toThrow(QueryException::class, 'no such table');
    } finally {
        qaIso()->leave();
    }

    // Second shape: the job forgot to leave, a worker reset the container scope,
    // and the next job enters a different tenant with A's transaction still open
    // on a cached connection object.
    qaIso()->enter('qa-iso-a');

    $leaked = DB::connection('tenant');
    $leaked->beginTransaction();

    expect($leaked->transactionLevel())->toBe(1);

    app()->forgetScopedInstances();

    qaIso()->enter('qa-iso-b');

    try {
        expect(qaIsoMarker())->toBe('qa-iso-b')
            ->and($leaked->transactionLevel())->toBe(0)
            ->and(fn (): mixed => $leaked->scalar('select value from marker'))
            ->toThrow(TenantConnectionMismatch::class);
    } finally {
        $leaked->disconnect();
        qaIso()->leave();
    }
});

it('shows what a held connection does without the guard, on an unguarded connection name', function () {
    // The negative control behind every stale-connection assertion in this file
    // and in TenancyTenantSwitchTest. "The guard refused" is only evidence that
    // the guard matters if an identical, unguarded connection would have served
    // the other database instead of failing - which is exactly what
    // Connection::reconnectIfMissingConnection() plus the manager's reconnector
    // do. Proven here on a connection name the tenant connection factory
    // does not treat as a tenant's, so no production code has to be touched to
    // show it.
    config()->set('database.connections.qa_iso_unguarded', [
        'driver' => 'sqlite',
        'database' => qaIsoDatabasePath('qa-iso-a'),
        'prefix' => '',
    ]);

    $held = DB::connection('qa_iso_unguarded');

    expect((string) $held->scalar('select value from marker'))->toBe('qa-iso-a');

    // The same moves Tenancy makes on a switch: rewrite the stanza, purge.
    config()->set('database.connections.qa_iso_unguarded.database', qaIsoDatabasePath('qa-iso-b'));
    DB::purge('qa_iso_unguarded');

    // The object nobody purged silently repoints itself at the new database.
    // That is the leak; the tenant connection's per-query guard is what turns
    // this exact behaviour into TenantConnectionMismatch.
    expect((string) $held->scalar('select value from marker'))->toBe('qa-iso-b');

    $held->disconnect();
    DB::purge('qa_iso_unguarded');
});

it('purges every tenant connection variant on teardown, and still refuses one held across a switch', function () {
    // Rewritten for review R-2, and the rewrite is the point.
    //
    // The first version asserted that `tenant::read` SURVIVED teardown, and
    // called that survival "what makes the refusals below meaningful". That was
    // characterising the unfixed behaviour as a precondition - asserting the
    // symptom instead of the contract, the same shape I had already caught and
    // fixed elsewhere in this file. DatabaseManager caches a read/write variant
    // under its own key and purge() unsets only the key it is given, so an open
    // handle on the previous shop's database used to outlive its scope inside
    // the manager. R-2 closed that, and it is the better behaviour: the
    // per-query guard should be the second line of defence, not the reason a
    // stale handle is tolerable - and it stops being sufficient the day FP-203
    // adds real read replicas.
    //
    // The contract this now asserts is the stronger pair: nothing tenant-shaped
    // outlives the scope, AND an object somebody held across the switch is
    // still refused. The second half keeps its discriminating power from the
    // held reference - the real "a repository cached the connection" shape -
    // rather than from the manager failing to clean up.
    qaIso()->enter('qa-iso-a');

    $read = DB::connection('tenant::read');

    expect((string) $read->scalar('select value from marker'))->toBe('qa-iso-a')
        ->and(array_keys(DB::getConnections()))->toContain('tenant::read');

    qaIso()->leave();

    // The contract: no tenant-shaped connection of any variant is left behind.
    $remaining = array_values(array_filter(
        array_map('strval', array_keys(DB::getConnections())),
        static fn (string $name): bool => $name === 'tenant' || str_starts_with($name, 'tenant::')
    ));

    expect($remaining)->toBe([], 'tenant connections outlived their scope: '.implode(', ', $remaining));

    // And asking for one again while no tenant is resolved is refused, rather
    // than quietly rebuilt against whatever is configured.
    expect(fn (): mixed => DB::connection('tenant::read')->scalar('select value from marker'))
        ->toThrow(NoTenantResolved::class);

    DB::purge('tenant::read');

    qaIso()->enter('qa-iso-b');

    try {
        // The half that still matters, and the reason this case exists: the
        // object captured before the switch is refused rather than reconnected
        // against tenant B. Level 3 is what does that, and nothing else does.
        expect(fn (): mixed => $read->scalar('select value from marker'))
            ->toThrow(TenantConnectionMismatch::class);
    } finally {
        $read->disconnect();
        qaIso()->leave();
    }
});

it('holds exactly one production write to the tenant connection configuration', function () {
    // AC-15's first clause, as a durable guard rather than a one-off audit: the
    // moment a second production file writes that key, this names it.
    $repository = dirname(__DIR__, 4);

    $roots = [
        $repository.DIRECTORY_SEPARATOR.'packages',
        $repository.DIRECTORY_SEPARATOR.'modules',
    ];

    $writers = [];

    // vendor/, storage/ and node_modules/ are pruned rather than filtered after
    // the fact: walking them costs several seconds of the gate and contains no
    // first-party code.
    $skipped = ['vendor', 'storage', 'node_modules', 'bootstrap'];

    foreach ($roots as $root) {
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);

        $pruned = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $current) use ($skipped): bool {
                return ! $current->isDir() || ! in_array($current->getFilename(), $skipped, true);
            }
        );

        /** @var iterable<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator($pruned);

        foreach ($files as $path => $file) {
            $normalised = str_replace('\\', '/', (string) $path);

            if (! str_ends_with($normalised, '.php') && ! str_ends_with($normalised, '.stub')) {
                continue;
            }

            // Tests are excluded by design: several of them write the stanza on
            // purpose, to prove the guard catches a configuration Tenancy did
            // not write.
            if (str_contains($normalised, '/tests/')) {
                continue;
            }

            $contents = (string) file_get_contents((string) $path);

            if (preg_match('/->set\(\s*[\'"]database\.connections\./', $contents) === 1
                || preg_match('/config\(\s*\[\s*[\'"]database\.connections\./', $contents) === 1) {
                $writers[] = $normalised;
            }
        }
    }

    expect($writers)->toHaveCount(1, 'writers: '.implode(', ', $writers))
        ->and($writers[0] ?? '')->toEndWith('packages/kernel/src/Tenancy/Tenancy.php');
});

it('keeps nothing of a tenant in the database manager between scopes', function () {
    qaIso()->run('qa-iso-a', function (): void {
        expect(qaIsoMarker())->toBe('qa-iso-a');
    });

    $retained = new ReflectionProperty(DatabaseManager::class, 'dynamicConnectionConfigurations');

    expect(array_keys(DB::getConnections()))->not->toContain('tenant')
        ->and(qaIsoStanza())->toBe(qaIsoPlaceholder())
        ->and($retained->getValue(app(DatabaseManager::class)))->toBe([])
        ->and(fn (): mixed => DB::connection('tenant')->scalar('select value from marker'))
        ->toThrow(NoTenantResolved::class);
});

it('refuses a tenant connection built through DatabaseManager::connectUsing with no tenant resolved', function () {
    // BUG-2 (T-003), re-closed at its root by T-007. connectUsing() calls
    // $this->factory->make($config, $name) directly instead of makeConnection(),
    // so a name-keyed extension, which was the tenant guard then, was never
    // consulted - and the resulting object was cached under the `tenant` name for
    // the rest of the process, so every later DB::connection('tenant') was
    // unguarded too. The guard now lives in the factory itself, which this route
    // does call.
    //
    // AC-15: "qa verifies there is no second, working path that achieves the
    // same effect." This is one.
    expect(qaIso()->current()->hasTenant())->toBeFalse();

    expect(function (): void {
        DB::connectUsing('tenant', [
            'driver' => 'sqlite',
            'database' => qaIsoDatabasePath('qa-iso-a'),
            'prefix' => '',
        ], true)->scalar('select value from marker');
    })->toThrow(NoTenantResolved::class);

    DB::purge('tenant');
});

it('never caches the refused connection under the tenant name', function () {
    // The refusal has to happen inside the factory, before
    // DatabaseManager::connectUsing() reaches
    // `$this->connections[$name] = $connection`. Before BUG-2 was fixed I measured
    // the unguarded object being installed as `tenant` for the rest of the
    // process, so every later DB::connection() inherited it. Refusing is not
    // enough on its own; refusing early is.
    foreach ([
        'connectUsing' => static function (): void {
            DB::connectUsing('tenant', [
                'driver' => 'sqlite',
                'database' => qaIsoDatabasePath('qa-iso-a'),
                'prefix' => '',
            ], true);
        },
        'build' => static function (): void {
            DB::build([
                'name' => 'tenant',
                'driver' => 'sqlite',
                'database' => qaIsoDatabasePath('qa-iso-a'),
                'prefix' => '',
            ]);
        },
    ] as $route => $bypass) {
        expect($bypass)->toThrow(NoTenantResolved::class);

        expect(array_keys(DB::getConnections()))->not->toContain('tenant');

        expect(fn (): mixed => DB::connection('tenant')->scalar('select value from marker'))
            ->toThrow(TenancyException::class);
    }
});

it('bricks the tenant connection loudly, rather than quietly, after a refused build()', function () {
    // The one thing the refusal cannot undo: build() writes
    // DatabaseManager::$dynamicConnectionConfigurations[$name] BEFORE it
    // delegates to connectUsing(), purge() never clears it, and
    // configuration() prefers it over config('database.connections.*') - so a
    // refused build() leaves the tenant name pointing at the caller's array for
    // the rest of the process.
    //
    // This is fail-closed and worth pinning as such: legitimate tenancy then
    // refuses with a message that names the cause, instead of the forbidden API
    // half-working. If anyone ever "fixes" this into something permissive, this
    // is what goes red.
    expect(function (): void {
        DB::build([
            'name' => 'tenant',
            'driver' => 'sqlite',
            'database' => qaIsoDatabasePath('qa-iso-b'),
            'prefix' => '',
        ]);
    })->toThrow(NoTenantResolved::class);

    $retained = new ReflectionProperty(DatabaseManager::class, 'dynamicConnectionConfigurations');

    expect(array_keys((array) $retained->getValue(app(DatabaseManager::class))))->toContain('tenant');

    $refusal = null;

    try {
        qaIso()->run('qa-iso-a', fn (): mixed => DB::connection('tenant')->scalar('select value from marker'));
    } catch (Throwable $exception) {
        $refusal = $exception;
    }

    expect($refusal)->toBeInstanceOf(TenantConnectionMismatch::class)
        ->and((string) $refusal?->getMessage())->toContain('only code allowed to write that configuration')
        // And no data came back from either tenant.
        ->and(qaIso()->current()->hasTenant())->toBeFalse();
});

it('keeps the per-query guard attached when the event dispatcher is faked', function () {
    // Rewritten for T-007 AC-27. This paragraph used to DECLINE to assert that
    // the build()/connectUsing() route is guarded under Event::fake(), on the
    // grounds that it was test-only state that ran "in the safe direction" and
    // that asserting it would pin a hole shut against ever being closed. That
    // reasoning was backwards: a guard that a test helper can switch off is a
    // guard nobody can rely on in any test, and the day a production code path
    // fakes or replaces the dispatcher the hole is real. FP-160 closed it by
    // moving the guard out of the event entirely, and the next case asserts it.
    //
    // What stays here is the ordinary route, unchanged: the two assertions this
    // case already made were green at every tip from T-003 onward and are the
    // regression guard that the move did not lose them.
    Event::fake();

    qaIso()->enter('qa-iso-a');

    $connection = DB::connection('tenant');

    expect((string) $connection->scalar('select value from marker'))->toBe('qa-iso-a');

    qaIso()->leave();
    qaIso()->enter('qa-iso-b');

    try {
        // The per-query guard is still attached, by the factory decoration,
        // with no dispatcher involved at all.
        expect(fn (): mixed => $connection->scalar('select value from marker'))
            ->toThrow(TenantConnectionMismatch::class);

        expect(qaIsoMarker())->toBe('qa-iso-b');
    } finally {
        $connection->disconnect();
        qaIso()->leave();
    }
});

it('does not retain a tenant connection after the switch that built it', function () {
    // T-007 AC-26. The positive control for "nothing is retained": twenty-five
    // switches, each one querying, and a weak reference to a connection from an
    // early switch that must be gone once the cycle collector has run. It states
    // the property a process-wide de-duplication map used to be accountable for,
    // without naming any implementation that provides it.
    $early = null;

    for ($iteration = 0; $iteration < 25; $iteration++) {
        qaIso()->run($iteration % 2 === 0 ? 'qa-iso-a' : 'qa-iso-b', function () use (&$early, $iteration): void {
            $connection = DB::connection('tenant');
            $connection->scalar('select value from marker');

            if ($iteration === 2) {
                $early = WeakReference::create($connection);
            }
        });
    }

    expect($early)->toBeInstanceOf(WeakReference::class);

    gc_collect_cycles();

    expect($early->get())->toBeNull('a connection from an earlier switch is still retained');
});

it('refuses a tenant connection built through DatabaseManager::build for another tenant', function () {
    // BUG-2, the cross-tenant half and the one that matters: inside tenant A's
    // scope, build() hands out a `tenant` connection pointed at tenant B, with
    // TenantContext still reporting A and no guard raised. That is the leak the
    // whole task exists to prevent, reached through a public framework API.
    qaIso()->enter('qa-iso-a');

    try {
        expect(function (): void {
            DB::build([
                'name' => 'tenant',
                'driver' => 'sqlite',
                'database' => qaIsoDatabasePath('qa-iso-b'),
                'prefix' => '',
            ])->scalar('select value from marker');
        })->toThrow(TenancyException::class);
    } finally {
        DB::purge('tenant');
        qaIso()->leave();
    }
});

/*
|--------------------------------------------------------------------------
| T-007: qa's own AC-23 table, AC-27, AC-28 and AC-30
|--------------------------------------------------------------------------
|
| Seven construction routes, three states, two dispatchers. The mechanism every
| route passes through is one thing - the `db.factory` binding, decorated by
| TenantConnectionFactory - and what these cases prove is that none of the routes
| has a way round it, not that the decoration exists.
|
| The three states are the spec's, and each has a different refusal:
|
|   none     no tenant is resolved                  NoTenantResolved
|   foreign  a tenant IS resolved, the stanza is    TenantConnectionMismatch
|            not the one Tenancy wrote for it       (AC-21: never NoTenantResolved
|                                                    while a tenant plainly is)
|   legit    the stanza Tenancy wrote               the query answers, with that
|                                                    tenant's row and no other
|
| Every refusal is also swept for the credential (AC-30), and a leaky helper
| proves the sweep could have seen one.
*/

/**
 * The one query every route runs, so a row coming back is a fact about which
 * database was reached.
 */
function qaIsoSql(): string
{
    return 'select value from marker';
}

/**
 * The key the live stanza carries its authenticator under, found by shape and
 * not by name: the only 32-character hexadecimal string in it. qa does not
 * import the name from production, so renaming it cannot make this file agree
 * with the code by construction.
 *
 * @param  array<string, mixed>  $stanza
 */
function qaIsoAuthenticatorKey(array $stanza): string
{
    $keys = array_keys(array_filter(
        $stanza,
        static fn (mixed $value): bool => is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1
    ));

    expect($keys)->toHaveCount(1, 'expected exactly one authenticator-shaped value in the live stanza');

    return (string) $keys[0];
}

/**
 * A read through an Eloquent model on the tenant connection, built the way a
 * module's model would be.
 */
function qaIsoModelRead(): mixed
{
    $model = new class extends Model
    {
        protected $connection = 'tenant';

        protected $table = 'marker';

        public $timestamps = false;
    };

    return $model->newQuery()->value('value');
}

/**
 * Put a stanza where the framework will read it for the name `tenant`, and drop
 * whatever is cached under it, so the next request has to build.
 *
 * @param  array<string, mixed>  $stanza
 */
function qaIsoUse(array $stanza): void
{
    config()->set('database.connections.tenant', $stanza);
    DB::purge('tenant');
    DB::purge('tenant::read');
}

/**
 * Run a closure that builds and queries, and report what came out: the refusal,
 * or the value that came back.
 *
 * @return array{0: ?Throwable, 1: mixed}
 */
function qaIsoAttempt(Closure $attempt): array
{
    try {
        return [null, $attempt()];
    } catch (Throwable $exception) {
        return [$exception, null];
    }
}

/**
 * Close a connection no manager owns, so its SQLite handle does not outlive the
 * test and break the directory delete on Windows. build(), connectUsing() and
 * the factory return the interface or the class depending on the route, and only
 * the class can disconnect.
 */
function qaIsoClose(ConnectionInterface $connection): void
{
    if ($connection instanceof Connection) {
        $connection->disconnect();
    }
}

/**
 * What an attempt came to, as one comparable string, so that a failing
 * assertion prints what actually happened - the refusal's class and message, or
 * the value that came back - instead of a bare "false is not true". (Pest's
 * toBe() takes no message argument: a second argument is dropped without a word,
 * which is why the outcome is the thing compared.)
 *
 * @param  array{0: ?Throwable, 1: mixed}  $attempt
 */
function qaIsoOutcome(array $attempt): string
{
    [$refusal, $value] = $attempt;

    return $refusal === null
        ? 'answered '.var_export($value, true)
        : 'refused '.$refusal::class.' ('.$refusal->getMessage().')';
}

/**
 * The same, reduced to the class: what a table row is expected to equal.
 *
 * @param  array{0: ?Throwable, 1: mixed}  $attempt
 */
function qaIsoClassOf(array $attempt): string
{
    return $attempt[0] === null ? 'answered '.var_export($attempt[1], true) : 'refused '.$attempt[0]::class;
}

/**
 * The seven routes of the spec's list. Each takes the stanza to hand the route
 * and says nothing else about the state it is run in.
 *
 * `$stanza` is what is *offered*: the config-driven routes install it in
 * config() first, the explicit ones pass it as an argument. Connections no
 * manager owns are closed here, because on Windows an open SQLite handle outlives
 * the test and breaks the fixture's directory delete.
 *
 * @return array<string, Closure(array<string, mixed>): mixed>
 */
function qaIsoRoutes(): array
{
    return [
        'DB::connection' => static function (array $stanza): mixed {
            qaIsoUse($stanza);

            return DB::connection('tenant')->scalar(qaIsoSql());
        },

        'an Eloquent model' => static function (array $stanza): mixed {
            qaIsoUse($stanza);

            return qaIsoModelRead();
        },

        'DB::build' => static function (array $stanza): mixed {
            $connection = DB::build(['name' => 'tenant'] + $stanza);

            try {
                return $connection->scalar(qaIsoSql());
            } finally {
                qaIsoClose($connection);
            }
        },

        'DB::connectUsing' => static fn (array $stanza): mixed => DB::connectUsing('tenant', $stanza, true)
            ->scalar(qaIsoSql()),

        'the factory called directly' => static function (array $stanza): mixed {
            $connection = app('db.factory')->make($stanza, 'tenant');

            try {
                return $connection->scalar(qaIsoSql());
            } finally {
                $connection->disconnect();
            }
        },

        'a reconnect after purge' => static function (array $stanza): mixed {
            qaIsoUse($stanza);
            DB::purge('tenant');

            return DB::reconnect('tenant')->scalar(qaIsoSql());
        },

        'a read/write split' => static function (array $stanza): mixed {
            qaIsoUse($stanza);

            return DB::connection('tenant::read')->scalar(qaIsoSql());
        },
    ];
}

/**
 * @return list<string>
 */
function qaIsoRouteNames(): array
{
    return array_keys(qaIsoRoutes());
}

/**
 * Whatever the connection manager caches that belongs to the tenant name, in
 * any of its read/write spellings.
 *
 * @return list<string>
 */
function qaIsoTenantKeys(): array
{
    return array_values(array_filter(
        array_map('strval', array_keys(DB::getConnections())),
        static fn (string $name): bool => $name === 'tenant' || str_starts_with($name, 'tenant::')
    ));
}

/**
 * Every stanza a forger could write while tenant A is resolved, by what the
 * forger knows. Called inside A's scope, so the live stanza exists to be copied.
 *
 * Every one of these points at tenant B's real SQLite file, or at A's with one
 * redirecting value changed: a refusal means something only if the thing refused
 * would otherwise have answered.
 *
 * @return array<string, array<string, mixed>>
 */
function qaIsoForgeries(): array
{
    $live = qaIsoStanza();
    $key = qaIsoAuthenticatorKey($live);
    $other = qaIsoDatabasePath('qa-iso-b');
    $plain = 'qa-iso-FORGED-PASSWORD-3e9a';

    $base = [
        'driver' => 'sqlite',
        'host' => 'qa-iso-qa-iso-b',
        'port' => 3306,
        'database' => $other,
        'username' => 'qa_iso_qa_iso_b',
        'password' => $plain,
        'prefix' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'options' => [],
    ];

    return [
        // F1: knows the published source and the resolved tenant id. The old
        // public marker was exactly this key with exactly this value.
        'F1: the retired marker key holding the tenant id' => $base + ['flexipos_tenant' => 'qa-iso-a'],
        'F1: an authenticator that is the tenant id' => $base + [$key => 'qa-iso-a'],
        'F1: an authenticator of the right shape that nobody minted' => $base + [$key => bin2hex(random_bytes(16))],

        // F2: runs inside the scope, reads the live stanza, copies every value
        // including the authenticator, and changes one thing.
        'F2: a copy with the database changed' => array_replace($live, ['database' => $other]),
        'F2: a copy with the host changed' => array_replace($live, ['host' => 'qa-iso-elsewhere']),
        'F2: a copy with the username changed' => array_replace($live, ['username' => 'qa_iso_somebody_else']),
        'F2: a copy with a read split injected' => $live + ['read' => ['database' => $other]],
    ];
}

/**
 * Everything a refusal prints that a log, a page or a terminal might carry.
 */
function qaIsoSurface(Throwable $refusal): string
{
    return (string) $refusal.PHP_EOL.$refusal->getMessage().PHP_EOL.$refusal->getTraceAsString();
}

/**
 * The fragments of either credential that appear anywhere on a refusal's surface.
 * The prefixes are there because a trace prints a string argument truncated, and
 * a truncated password is still most of one.
 *
 * @return list<string>
 */
function qaIsoCredentialsOn(Throwable $refusal): array
{
    $surface = qaIsoSurface($refusal);

    return array_values(array_filter(
        [qaIsoPassword(), 'qa-iso-PASSWORD', 'qa-iso-FORGED-PASSWORD-3e9a', 'qa-iso-FORGED-'],
        static fn (string $needle): bool => str_contains($surface, $needle)
    ));
}

/**
 * Whether the route hands back a connection the manager does not cache.
 */
function qaIsoHoldsHandle(string $route): bool
{
    return in_array($route, ['DB::build', 'the factory called directly'], true);
}

/**
 * Put the application back to "no tenant, nothing cached, nothing retained"
 * between two rows of one table, so a row cannot inherit another row's state.
 *
 * The retained-configuration reset is not production behaviour and not a
 * workaround for it: DatabaseManager::build() writes the caller's array into
 * `dynamicConnectionConfigurations['tenant']`, no public API clears it, and
 * configuration() prefers it over config() - so after any build() (refused or
 * not) the next enter() in the same process is itself refused. That is fail-closed
 * and a case below pins it. Rows of a table need to be independent of it, so the
 * property is emptied here, by reflection, which only a test may do.
 */
function qaIsoFresh(): void
{
    qaIso()->leave();

    $manager = app(DatabaseManager::class);

    (new ReflectionProperty(DatabaseManager::class, 'dynamicConnectionConfigurations'))->setValue($manager, []);

    foreach (qaIsoTenantKeys() as $name) {
        DB::purge($name);
    }

    app()->forgetScopedInstances();
}

/**
 * One route through all three of the spec's states, as a map of row name to
 * what the row came to. Built by one function and compared with one expected map
 * in one assertion, so a failure prints every row that differs rather than
 * stopping at the first.
 *
 * @return array{rows: array<string, string>, credentials: list<string>, leftovers: list<string>}
 */
function qaIsoTable(string $route): array
{
    $rows = [];
    $credentials = [];
    $leftovers = [];

    $record = static function (string $row, Closure $run) use (&$rows, &$credentials, &$leftovers): void {
        // Keys cached before the attempt are the scope's own (entering a tenant
        // opens one to prove the database answers); only a key the refused
        // request ADDED is a leftover.
        $before = qaIsoTenantKeys();
        $attempt = qaIsoAttempt($run);
        $rows[$row] = qaIsoClassOf($attempt);

        if ($attempt[0] !== null) {
            foreach (qaIsoCredentialsOn($attempt[0]) as $needle) {
                $credentials[] = $row.': '.$needle;
            }

            // A refused request must not leave a cached connection behind.
            foreach (array_diff(qaIsoTenantKeys(), $before) as $name) {
                $leftovers[] = $row.': '.$name;
            }
        }
    };

    // State 1 - none. A stanza a queue worker would have left behind: the
    // genuine one, captured inside a scope that has since ended.
    $stale = qaIso()->run('qa-iso-a', fn (): array => qaIsoStanza());

    $record('no tenant resolved', fn (): mixed => qaIsoRoutes()[$route]($stale));

    qaIsoFresh();

    // State 2 - foreign. Tenant A is resolved and the stanza is somebody
    // else's. Every forgery is a separate row.
    $names = array_keys(qaIsoExpectedRows($route));

    foreach ($names as $name) {
        if (! str_starts_with($name, 'F')) {
            continue;
        }

        qaIso()->enter('qa-iso-a');

        // Built inside this row's own scope: a copy of the live stanza carries
        // THIS scope's authenticator, so what refuses it is the changed value
        // and not a token that has since been superseded.
        $forged = qaIsoForgeries()[$name];

        $record($name, fn (): mixed => qaIsoRoutes()[$route]($forged));

        qaIsoFresh();
    }

    // State 3 - legit. The positive control that makes every refusal above
    // mean something: the same route, the stanza left exactly as Tenancy wrote
    // it, answers with this tenant's row and nobody else's.
    qaIso()->enter('qa-iso-a');

    $genuine = qaIsoStanza();

    $rows['the stanza Tenancy wrote'] = qaIsoClassOf(qaIsoAttempt(fn (): mixed => qaIsoRoutes()[$route]($genuine)));

    qaIsoFresh();

    // State 4 - held past the end of its scope. Only the two routes whose
    // connection no manager caches can be held: leave() purges everything the
    // manager owns, which nulls the handle and sends the next query back through
    // the factory, so for every other route this state is the first one again.
    // For these two the handle stays open and the per-query guard is the ONLY
    // thing between the ended scope and the data - this is the row that goes red
    // when the guard is not attached (AC-32).
    if (qaIsoHoldsHandle($route)) {
        qaIso()->enter('qa-iso-a');

        $held = $route === 'DB::build'
            ? DB::build(['name' => 'tenant'] + qaIsoStanza())
            : app('db.factory')->make(qaIsoStanza(), 'tenant');

        $held->scalar(qaIsoSql());

        qaIso()->leave();

        $rows['held past the end of its scope'] = qaIsoClassOf(qaIsoAttempt(fn (): mixed => $held->scalar(qaIsoSql())));

        qaIsoClose($held);

        qaIsoFresh();
    }

    $leftovers = array_merge($leftovers, array_map(
        static fn (string $name): string => 'after the scope: '.$name,
        qaIsoTenantKeys()
    ));

    return ['rows' => $rows, 'credentials' => $credentials, 'leftovers' => $leftovers];
}

/**
 * What the table must say, for any route.
 *
 * @return array<string, string>
 */
function qaIsoExpectedRows(string $route): array
{
    // Names only, from a throwaway scope: the stanzas themselves are rebuilt
    // inside the table, where the live one exists to be copied.
    qaIso()->enter('qa-iso-a');

    $names = array_keys(qaIsoForgeries());

    qaIsoFresh();

    $expected = ['no tenant resolved' => 'refused '.NoTenantResolved::class];

    foreach ($names as $name) {
        // The exact class, and the stage: refused by the factory, before any
        // driver is asked to open anything. A missing-file error, a driver
        // error, NoTenantResolved (wrong while a tenant plainly is resolved,
        // AC-21) or an answer from tenant B would each be a different value
        // here, and each prints as itself.
        $expected[$name] = 'refused '.TenantConnectionMismatch::class;
    }

    $expected['the stanza Tenancy wrote'] = "answered 'qa-iso-a'";

    if (qaIsoHoldsHandle($route)) {
        $expected['held past the end of its scope'] = 'refused '.NoTenantResolved::class;
    }

    return $expected;
}

it('gives every route the same three answers', function (string $route) {
    $table = qaIsoTable($route);

    // Rows, then AC-30 (no credential on any refusal) and "a refused request
    // caches nothing", each as its own comparison of a list with [].
    expect($table['rows'])->toBe(qaIsoExpectedRows($route))
        ->and($table['credentials'])->toBe([])
        ->and($table['leftovers'])->toBe([]);
})->with(qaIsoRouteNames());

it('gives every route the same three answers when the dispatcher is faked', function (string $route) {
    // AC-28. The same body of code and the same expected map as the case above,
    // with only the dispatcher different: identical results are the claim, so
    // there must not be a second set of expectations that merely resembles the
    // first.
    Event::fake();

    $table = qaIsoTable($route);

    expect($table['rows'])->toBe(qaIsoExpectedRows($route))
        ->and($table['credentials'])->toBe([])
        ->and($table['leftovers'])->toBe([]);
})->with(qaIsoRouteNames());
it('could see a credential on a refusal surface if one were there', function () {
    // The control for every "a refusal printed a credential" assertion above.
    // Without it, an empty finding list could mean the detector is blind. Args
    // are switched on for this one exception because a production php.ini may
    // legitimately switch them off, and then there is nothing for a trace to
    // leak - which is a property of the ini file, not of the code under test.
    $before = ini_set('zend.exception_ignore_args', '0');

    try {
        $leaky = static function (string $password): never {
            throw new RuntimeException('connecting failed');
        };

        $refusal = qaIsoAttempt(fn (): mixed => $leaky(qaIsoPassword()))[0];
    } finally {
        ini_set('zend.exception_ignore_args', $before === false ? '0' : $before);
    }

    expect($refusal)->toBeInstanceOf(RuntimeException::class)
        ->and(qaIsoCredentialsOn($refusal))->not->toBe([]);
});

it('refuses a connection no manager owns when it is queried after its scope has ended', function (string $route) {
    // The one place the per-query guard, and nothing else, is what stops a held
    // connection: DB::build() and a direct factory call hand back an object the
    // manager never caches, so leave() has nothing to purge and its PDO stays
    // open. The construction-time checks ran long ago and passed. Every other
    // route reconnects through the factory when its PDO is nulled, which would
    // refuse for a different reason and hide a missing guard - this is the case
    // that goes red when the guard is not attached.
    //
    // Expected: NoTenantResolved, with a real dispatcher and with a faked one.
    //
    // AC-27 SHAPE B, post-scope. NOT red-first (plan R14): for build() the old
    // design was already green here by accident, because leave() purges the name,
    // the nulled handle reconnects through the manager, and that route the old
    // extension did cover. Kept as a regression guard on that accident. For the
    // direct factory call the handle is never purged; see the next case.
    foreach ([false, true] as $fake) {
        qaIsoFresh();

        if ($fake) {
            Event::fake();
        }

        qaIso()->enter('qa-iso-a');

        $held = $route === 'DB::build'
            ? DB::build(['name' => 'tenant'] + qaIsoStanza())
            : app('db.factory')->make(qaIsoStanza(), 'tenant');

        // The control: it answered, so the refusal is about the ended scope.
        expect((string) $held->scalar(qaIsoSql()))->toBe('qa-iso-a');

        qaIso()->leave();

        $none = qaIsoAttempt(fn (): mixed => $held->scalar(qaIsoSql()));

        qaIsoClose($held);

        $label = $route.($fake ? ' under Event::fake()' : '');

        expect($label.' after the scope -> '.qaIsoClassOf($none))
            ->toBe($label.' after the scope -> refused '.NoTenantResolved::class);
    }
})->with(['DB::build', 'the factory called directly']);

it('refuses a factory-built connection held into another tenant\'s scope, with either dispatcher', function () {
    // AC-27 SHAPE A, the HELD HANDLE, and the only red-first shape (plan R14).
    // The route returns A's own row inside B's scope: a connection
    // no manager caches keeps its PDO across the switch, so nothing but the
    // per-query guard stands between B's scope and A's data. (build() cannot be
    // used for this shape - see the case after next.)
    foreach ([false, true] as $fake) {
        qaIsoFresh();

        if ($fake) {
            Event::fake();
        }

        qaIso()->enter('qa-iso-a');

        $held = app('db.factory')->make(qaIsoStanza(), 'tenant');

        expect((string) $held->scalar(qaIsoSql()))->toBe('qa-iso-a');

        qaIso()->leave();
        qaIso()->enter('qa-iso-b');

        try {
            $switched = qaIsoAttempt(fn (): mixed => $held->scalar(qaIsoSql()));
        } finally {
            qaIso()->leave();
            $held->disconnect();
        }

        $label = ($fake ? 'faked' : 'real').' dispatcher';

        expect($label.' -> '.qaIsoClassOf($switched))
            ->toBe($label.' -> refused '.TenantConnectionMismatch::class)
            ->and(str_contains((string) $switched[0]?->getMessage(), 'qa-iso-a'))->toBeTrue();
    }
});

it('refuses a connectUsing connection queried after a tenant switch when the dispatcher is faked', function () {
    // T-007 AC-27, SHAPE B (switch, purged handle). MEASURED red at the T-006 tip
    // by running dev's equivalent of this case there (2026-10-07, a scratch
    // worktree at cef6a17): the held connectUsing() object answered 'tenant-b'
    // from inside tenant B's scope, i.e. an object built for A served as B. That
    // is a repointing, not A's rows in B's scope, which is why shape A below is
    // the stronger demonstration - but it is red, so plan R14's sentence that
    // neither build() nor connectUsing() can demonstrate the defect is true of
    // build() after the scope ends and not of connectUsing() after a switch.
    // connectUsing() caches
    // under the connection name, so entering B purges it and the manager then
    // reconnects the held object against whatever is configured by then; the
    // guard on the object is what refuses that.
    //
    // Stage and class expected: the per-query guard, TenantConnectionMismatch,
    // naming tenant A as the one the connection was built for.
    Event::fake();

    qaIso()->enter('qa-iso-a');

    $connection = DB::connectUsing('tenant', qaIsoStanza(), true);

    // The control, inside the same test: before the switch it answers, so the
    // refusal below is about the switch and not about a connection that never
    // worked.
    expect((string) $connection->scalar(qaIsoSql()))->toBe('qa-iso-a');

    qaIso()->leave();
    qaIso()->enter('qa-iso-b');

    try {
        $attempt = qaIsoAttempt(fn (): mixed => $connection->scalar(qaIsoSql()));

        expect(qaIsoClassOf($attempt))->toBe('refused '.TenantConnectionMismatch::class)
            ->and(str_contains((string) $attempt[0]?->getMessage(), 'qa-iso-a'))->toBeTrue()
            // And tenant B's own connection still works beside it.
            ->and(qaIsoMarker())->toBe('qa-iso-b');
    } finally {
        DB::purge('tenant');
        qaIso()->leave();
    }
});

it('refuses to enter another tenant once the tenant name has been built, rather than serve either', function () {
    // AC-27's build() half cannot be asserted at the query, and this case says
    // why instead of pretending otherwise. DatabaseManager::build() writes the
    // caller's array into dynamicConnectionConfigurations['tenant'] before it
    // delegates; nothing public clears it and configuration() prefers it to
    // config(). So after ANY build() of the tenant name, even of the genuine
    // stanza, the NEXT tenant's enter() reads tenant A's retained array and is
    // refused while entering - the switch fails before a query can be made.
    //
    // That is fail-closed, predates this task (the T-003 case above pins it for a
    // refused build()), and plan R1 accepted it. It is also a worker-wide outage
    // until restart if a module ever builds the tenant name, which is why it is
    // asserted: if someone makes it permissive this goes red, and if someone
    // makes it recover this goes red too and the message above is the thing to
    // reconsider rather than the test.
    qaIso()->enter('qa-iso-a');

    $built = DB::build(['name' => 'tenant'] + qaIsoStanza());

    expect((string) $built->scalar(qaIsoSql()))->toBe('qa-iso-a');

    qaIso()->leave();

    qaIsoClose($built);

    $entering = qaIsoAttempt(function (): mixed {
        qaIso()->enter('qa-iso-b');

        return qaIsoMarker();
    });

    expect(qaIsoClassOf($entering))->toBe('refused '.TenantConnectionMismatch::class)
        ->and(qaIso()->current()->hasTenant())->toBeFalse();

    qaIsoFresh();

    // And it is the retained array that does it, not the tenant: the same
    // switch with that array cleared works.
    qaIso()->enter('qa-iso-b');

    try {
        expect(qaIsoMarker())->toBe('qa-iso-b');
    } finally {
        qaIso()->leave();
    }
});
it('reaches only its own tenant when the genuine stanza is rebuilt under another connection name', function () {
    // The residual the plan accepted (R7) and the digest's exclusion of `name`
    // forced: the digest binds everything that decides WHICH database is opened,
    // and the connection's name decides none of it. So a genuine stanza rebuilt
    // under another name is vouched for - and that has to be a harmless thing to
    // be able to do, which is what is asserted here instead of taken on trust.
    qaIso()->enter('qa-iso-a');

    $genuine = qaIsoStanza();
    $rebuilt = DB::build(['name' => 'qa_iso_other'] + $genuine);

    try {
        // It reaches only the forger's own tenant.
        expect((string) $rebuilt->scalar(qaIsoSql()))->toBe('qa-iso-a');

        // Anything else in the call changing is still refused, under the new
        // name exactly as under the old one: the name is the only free variable.
        $changed = qaIsoAttempt(fn (): mixed => DB::build(
            ['name' => 'qa_iso_other'] + array_replace($genuine, ['database' => qaIsoDatabasePath('qa-iso-b')])
        )->scalar(qaIsoSql()));

        expect(qaIsoClassOf($changed))->toBe('refused '.TenantConnectionMismatch::class);

        qaIso()->leave();

        // And it is not a way to keep a tenant connection past its scope: the
        // per-query guard is attached under the new name too.
        $after = qaIsoAttempt(fn (): mixed => $rebuilt->scalar(qaIsoSql()));

        expect(qaIsoClassOf($after))->toBe('refused '.NoTenantResolved::class);
    } finally {
        qaIsoClose($rebuilt);
        qaIso()->leave();
    }

    // Rebuilding the genuine stanza once the scope has ended is refused outright,
    // because the record that vouched for it is gone.
    $late = qaIsoAttempt(fn (): mixed => DB::build(['name' => 'qa_iso_other'] + $genuine));

    expect(qaIsoClassOf($late))->toBe('refused '.NoTenantResolved::class);
});

it('has no code anywhere in the tenancy namespace that refers to a connection event', function () {
    // AC-28's search half, over code and not prose: the docblocks in this
    // namespace explain what the removed listener used to do, by name, and a
    // plain text search would read that history as a dependency.
    $directory = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Tenancy';

    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBeGreaterThan(5, 'the search found almost no files, so it proves nothing');

    $mentions = [];
    $sawFactory = false;
    $sawWeakMap = false;

    foreach ($files as $path) {
        $identifiers = [];

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $identifiers[] = $token[1];

            if (str_contains($token[1], 'ConnectionEstablished')) {
                $mentions[] = basename($path).': '.$token[1];
            }

            if ($token[1] === 'TenantConnectionFactory') {
                $sawFactory = true;
            }
        }

        // A WeakMap is fine - the credential holders use one, keyed by the
        // secret. What AC-25 forbids is one that holds a connection, so a file
        // in code mentioning both is the finding.
        $holdsConnection = in_array('Connection', $identifiers, true)
            || in_array('Illuminate\Database\Connection', $identifiers, true)
            || in_array('\Illuminate\Database\Connection', $identifiers, true);

        if (in_array('WeakMap', $identifiers, true)) {
            $sawWeakMap = true;

            if ($holdsConnection) {
                $mentions[] = basename($path).': a WeakMap beside a Connection';
            }
        }
    }

    // The controls: the same scan did read code, and did see a WeakMap where one
    // legitimately is, so an empty list is not an empty or a blind scan.
    expect($sawFactory)->toBeTrue()
        ->and($sawWeakMap)->toBeTrue()
        ->and($mentions)->toBe([]);
});

afterAll(function () {
    // Event::fake() records every event it is handed, and ConnectionEstablished
    // carries the Connection, so a faked dispatcher keeps every connection of its
    // test alive in a cycle with the application. Without a collection pass the
    // SQLite handles are still open when the fixture directory is removed, which
    // on Windows leaves the directory behind.
    gc_collect_cycles();

    // Plain PHP, not the File facade: hooks of this kind run outside the
    // application, where no facade root exists.
    foreach (glob(qaIsoRoot().DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    clearstatcache();

    if (is_dir(qaIsoRoot())) {
        rmdir(qaIsoRoot());
    }
});

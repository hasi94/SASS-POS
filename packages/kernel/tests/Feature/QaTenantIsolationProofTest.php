<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenancyServiceProvider;
use Illuminate\Database\DatabaseManager;
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
 * TWO CASES IN THIS FILE ARE EXPECTED TO BE RED until BUG-2 is fixed. They are
 * the last two, both named "... built through DatabaseManager ...". See
 * docs/qa/T-003-tenant-connections.md, BUG-2.
 */

uses(TestCase::class);

/**
 * This file's own fixture root, deliberately separate from the one
 * TenancyResolutionTest creates, so neither file can mask a failure in the
 * other by leaving state behind.
 */
function qaIsoRoot(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'qa-tenancy-isolation');
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
    qaIsoCreateDatabase('qa-iso-a');
    qaIsoCreateDatabase('qa-iso-b');

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

    // Twice, allowing for Windows: File::deleteDirectory() opens a
    // FilesystemIterator over the directory and then rmdir()s it, and the
    // iterator's handle can outlive the unset, leaving an empty directory
    // behind. A second pass over the now-empty directory always succeeds.
    File::deleteDirectory(qaIsoRoot());

    clearstatcache();

    if (File::isDirectory(qaIsoRoot())) {
        File::deleteDirectory(qaIsoRoot());
    }
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
        Tenancy::TENANT_MARKER => 'qa-iso-a',
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
    qaIso()->enter('qa-iso-a');

    try {
        // Same shape, same marker, same resolved tenant - but an unopenable
        // database. The guard lets this through and the driver is the one that
        // complains, which is what makes the previous test's NoTenantResolved
        // meaningful.
        config()->set(
            'database.connections.tenant.database',
            qaIsoRoot().DIRECTORY_SEPARATOR.'no-such-dir'.DIRECTORY_SEPARATOR.'x.sqlite'
        );

        DB::purge('tenant');

        $refusal = null;

        try {
            DB::connection('tenant')->scalar('select 1');
        } catch (Throwable $exception) {
            $refusal = $exception;
        }

        expect($refusal)->toBeInstanceOf(Throwable::class)
            ->and($refusal)->not->toBeInstanceOf(TenancyException::class);
    } finally {
        qaIso()->leave();
    }
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
    // do. Proven here on a connection name the tenancy extension does not
    // cover, so no production code has to be touched to show it.
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
    // BUG-2. connectUsing() calls $this->factory->make($config, $name) directly
    // instead of makeConnection(), so the name-keyed extension that IS the
    // tenant guard is never consulted - and the resulting object is then cached
    // under the `tenant` name for the rest of the process, so every later
    // DB::connection('tenant') is unguarded too.
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
    // The BUG-2 fix relies on DatabaseManager::connectUsing() dispatching
    // ConnectionEstablished *before* `$this->connections[$name] = $connection`.
    // Before the fix I measured the unguarded object being installed as
    // `tenant` for the rest of the process, so every later DB::connection()
    // inherited it. Refusing is not enough on its own; refusing early is.
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
    // `dev` kept the connection extension as well as the listener, on the
    // grounds that faking the dispatcher would otherwise silently disable a
    // security guard. Checked rather than accepted: Event::fake() swaps the
    // `events` binding, and DatabaseManager resolves it from the container on
    // every dispatch, so the listener genuinely stops firing. The reasoning
    // holds - and the extension is what keeps the ordinary route guarded.
    //
    // Known and deliberately not asserted either way: under Event::fake() the
    // build()/connectUsing() route is unguarded again, because only the
    // listener covers it. That is test-only state - production always has a
    // real dispatcher - and the divergence runs in the safe direction
    // (production refuses what a faked test allows). Asserting it here would
    // pin a hole shut against ever being closed.
    Event::fake();

    qaIso()->enter('qa-iso-a');

    $connection = DB::connection('tenant');

    expect((string) $connection->scalar('select value from marker'))->toBe('qa-iso-a');

    qaIso()->leave();
    qaIso()->enter('qa-iso-b');

    try {
        // Level 3 is still attached, from the extension, with no dispatcher
        // involved at all.
        expect(fn (): mixed => $connection->scalar('select value from marker'))
            ->toThrow(TenantConnectionMismatch::class);

        expect(qaIsoMarker())->toBe('qa-iso-b');
    } finally {
        $connection->disconnect();
        qaIso()->leave();
    }
});

it('does not accumulate guarded connections across many tenant switches', function () {
    // The de-duplication map is static and process-wide, so "weak" has to mean
    // weak: a purged connection must be collected, and twenty-five switches
    // must not leave twenty-five entries behind.
    $property = new ReflectionProperty(TenancyServiceProvider::class, 'guardedConnections');

    $count = static function () use ($property): int {
        $map = $property->getValue();

        return $map instanceof WeakMap ? count($map) : -1;
    };

    // Measured relatively, never against an absolute, because the map outlives
    // individual tests by design and other tests' connections may still be
    // alive when this one runs.
    gc_collect_cycles();
    $before = $count();

    expect($before)->toBeGreaterThanOrEqual(0);

    for ($iteration = 0; $iteration < 25; $iteration++) {
        qaIso()->run($iteration % 2 === 0 ? 'qa-iso-a' : 'qa-iso-b', function (): void {
            DB::connection('tenant')->scalar('select value from marker');
        });
    }

    gc_collect_cycles();

    expect($count() - $before)->toBeLessThan(25, 'guarded connections accumulate one per tenant switch');

    // And the specific entry for a connection nobody holds any more really goes.
    $low = $count();

    qaIso()->enter('qa-iso-a');
    $held = DB::connection('tenant');
    $held->scalar('select value from marker');
    $high = $count();
    qaIso()->leave();

    expect($high)->toBeGreaterThan($low, 'the held connection was never registered');

    $held->disconnect();
    unset($held);
    gc_collect_cycles();

    expect($count())->toBeLessThan($high, 'a purged, unreferenced connection was not collected');
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

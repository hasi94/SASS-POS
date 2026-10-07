<?php

use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Flexipos\Kernel\Tenancy\TenancyServiceProvider;
use Flexipos\Kernel\Tenancy\TenantConnectionFactory;
use Flexipos\Kernel\Tenancy\TenantModel;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
 * T-007 FP-160: one construction choke point.
 *
 * Before this task the guarantee was spread over three attachments - a
 * name-keyed connection extension, a ConnectionEstablished listener and a
 * static WeakMap de-duplicating between them - and the one route that reached
 * only the listener (DB::build()/connectUsing()) lost its guard the moment a
 * test faked the dispatcher. This file states the property that replaces all of
 * that: every way a `tenant` Connection can be constructed goes through
 * TenantConnectionFactory, which is the factory DatabaseManager itself was
 * handed.
 *
 * Covers AC-21, AC-23, AC-24, AC-25, AC-27, AC-28 and AC-30, plus the two
 * installation invariants of R7 - the manager's injected factory is ours, and
 * nothing is registered as a name-keyed extension for the tenant connection,
 * because makeConnection() consults those BEFORE the factory.
 *
 * The fixture helpers come from ../Support/tenancy-fixture.php, required below
 * rather than borrowed from whichever file PHPUnit happened to collect first
 * (T-004 FP-164).
 */

require_once __DIR__.'/../Support/tenancy-fixture.php';

uses(TestCase::class);

/**
 * An Eloquent model on the shared tenant connection, as every FP-204+ business
 * model will be. Its own class, so no other file's probe model can change what
 * this one proves.
 */
final class ChokePointProbeModel extends TenantModel
{
    public $timestamps = false;

    protected $table = 'marker';
}

/**
 * Run the callback, swallow whatever it refuses with, and return it.
 */
function chokePointRefusalOf(Closure $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * A hand-written stanza that really would open the named fixture tenant's
 * database: every key the driver needs, the fixture's own credential value, and
 * nothing of Tenancy's.
 *
 * It points at a real SQLite file on purpose. A refusal only means something if
 * the thing refused would otherwise have returned a row.
 *
 * @return array<string, mixed>
 */
function chokePointForeignStanza(string $tenant): array
{
    return [
        'driver' => 'sqlite',
        'host' => 'choke-point-'.$tenant,
        'port' => 3306,
        'database' => tenancyFixtureDatabasePath($tenant),
        'username' => 'choke_point_app',
        // A plain string, which is what anything writing this stanza behind
        // Tenancy's back would use. AC-30 asserts it reaches no refusal.
        'password' => tenancyFixturePassword(),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'options' => [],
    ];
}

/**
 * The same stanza as a read/write split, which is the shape that makes
 * DatabaseManager cache `tenant::read` and `tenant::write` and that sends
 * ConnectionFactory::make() down its createReadWriteConnection() branch.
 *
 * @param  array<string, mixed>  $stanza
 * @return array<string, mixed>
 */
function chokePointSplitStanza(array $stanza): array
{
    $database = $stanza['database'] ?? '';

    return array_merge($stanza, [
        'read' => ['database' => $database],
        'write' => ['database' => $database],
    ]);
}

/**
 * Every construction route of the spec's list, each as a closure that builds the
 * tenant connection its own way and runs one query through it.
 *
 * The second argument says whether the stanza is the one Tenancy wrote. Only the
 * read/write row needs it: Tenancy never writes a split, so injecting `read`
 * into a legitimate stanza would be a forgery in its own right (AC-14) and this
 * row would then assert the opposite of AC-23's third column. The route itself is
 * identified by the suffixed cache key, which is what the spec names.
 *
 * @return array<string, Closure(array<string, mixed>, bool): mixed>
 */
function chokePointRoutes(): array
{
    return [
        'DB::connection' => static fn (array $stanza, bool $authentic): mixed => DB::connection('tenant')
            ->scalar('select value from marker'),

        'an Eloquent model' => static fn (array $stanza, bool $authentic): mixed => ChokePointProbeModel::query()
            ->value('value'),

        'DB::build' => static fn (array $stanza, bool $authentic): mixed => DB::build(['name' => 'tenant'] + $stanza)
            ->scalar('select value from marker'),

        'DB::connectUsing' => static fn (array $stanza, bool $authentic): mixed => DB::connectUsing('tenant', $stanza, true)
            ->scalar('select value from marker'),

        'the connection factory directly' => static function (array $stanza, bool $authentic): mixed {
            // 'db.factory' is the binding DatabaseServiceProvider registers and
            // the one DatabaseManager is constructed from; asking the container
            // for ConnectionFactory::class instead would auto-wire a second,
            // undecorated factory and prove nothing.
            $connection = app('db.factory')->make($stanza, 'tenant');

            try {
                return $connection->scalar('select value from marker');
            } finally {
                // This is the one route whose connection the manager never
                // caches, so no purge() reaches it. Closing it here keeps the
                // SQLite file handle from outliving the test and making the
                // fixture's afterAll unlink() fail on Windows.
                $connection->disconnect();
            }
        },

        'a reconnect after purge' => static function (array $stanza, bool $authentic): mixed {
            DB::purge('tenant');

            return DB::reconnect('tenant')->scalar('select value from marker');
        },

        'a read/write split' => static function (array $stanza, bool $authentic): mixed {
            if (! $authentic) {
                config()->set('database.connections.tenant', chokePointSplitStanza($stanza));
                DB::purge('tenant');
            }

            return DB::connection('tenant::read')->scalar('select value from marker');
        },
    ];
}

/**
 * @return list<string>
 */
function chokePointRouteNames(): array
{
    return array_keys(chokePointRoutes());
}

/**
 * Every name the connection manager currently caches something tenant-shaped
 * under, base name and read/write suffixes alike.
 *
 * @return list<string>
 */
function chokePointCachedTenantKeys(): array
{
    $cached = [];

    foreach (array_keys(DB::getConnections()) as $name) {
        $name = (string) $name;

        if ($name === 'tenant' || str_starts_with($name, 'tenant::')) {
            $cached[] = $name;
        }
    }

    return $cached;
}

/**
 * How many callbacks of the named Connection hook list are attached.
 */
function chokePointHookCount(Connection $connection, string $property): int
{
    $hooks = (new ReflectionProperty(Connection::class, $property))->getValue($connection);

    return count((array) $hooks);
}

/**
 * Every class declared under packages/kernel/src/Tenancy/, derived from the
 * directory rather than listed, so a new file is swept with no edit here.
 *
 * @return list<string>
 */
function chokePointTenancyClasses(): array
{
    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Tenancy';
    $classes = [];

    /** @var iterable<string, SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $path => $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr((string) $path, strlen($root) + 1, -4);
        $class = 'Flexipos\\Kernel\\Tenancy\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        if (class_exists($class) || interface_exists($class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * Every Connection reachable from the given value through arrays and WeakMaps -
 * the two containers something could hold one in without declaring it.
 *
 * Deliberately not a walk into arbitrary objects: the claim under test is about
 * what the tenancy code keeps, not about the whole object graph of the framework
 * a Connection legitimately belongs to.
 *
 * @return list<string>
 */
function chokePointConnectionsIn(mixed $value, int $depth = 0): array
{
    if ($depth > 4) {
        return [];
    }

    if ($value instanceof Connection) {
        return ['a '.$value::class];
    }

    $found = [];

    if ($value instanceof WeakMap) {
        foreach ($value as $key => $held) {
            $found = array_merge(
                $found,
                chokePointConnectionsIn($key, $depth + 1),
                chokePointConnectionsIn($held, $depth + 1)
            );
        }

        return $found;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            $found = array_merge($found, chokePointConnectionsIn($item, $depth + 1));
        }
    }

    return $found;
}

/**
 * Every source file under packages/kernel/src/Tenancy/, concatenated with its
 * whitespace collapsed, optionally with every comment and docblock removed.
 *
 * Collapsed because a line-oriented search for a phrase is exactly how a false
 * pass is produced: a reformatted or wrapped occurrence is invisible to grep and
 * present in the code.
 *
 * Comments removable because "the code refers to X" and "the file mentions X"
 * are different claims, and AC-28 is the first one. The docblocks of
 * TenantConnectionFactory and TenancyServiceProvider name the mechanisms FP-160
 * removed, with the measurement behind each; that is the explanation a reader
 * needs, and it is not a dependency.
 */
function chokePointTenancySource(bool $stripComments = false): string
{
    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Tenancy';
    $source = '';

    /** @var iterable<string, SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $path => $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents((string) $path);
        $source .= $stripComments ? chokePointStripComments($contents) : $contents;
    }

    return (string) preg_replace('/\s+/', ' ', $source);
}

/**
 * The same PHP with every comment and docblock replaced by a space.
 *
 * Tokenised rather than pattern-matched: a regular expression over `/* ... *` +
 * `/` also eats the contents of any string literal that happens to look like a
 * comment, which is the sort of silent inaccuracy a security assertion cannot
 * afford.
 */
function chokePointStripComments(string $php): string
{
    $stripped = '';

    foreach (token_get_all($php) as $token) {
        if (is_array($token)) {
            $stripped .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

            continue;
        }

        $stripped .= $token;
    }

    return $stripped;
}

/**
 * AC-23's first column: no tenant resolved, a stanza that would otherwise
 * connect, every route refused with NoTenantResolved and nothing cached.
 */
function chokePointAssertNoTenantIsRefused(string $route): void
{
    $stanza = chokePointForeignStanza('tenant-a');

    config()->set('database.connections.tenant', $stanza);
    DB::purge('tenant');

    $returned = null;

    $refusal = chokePointRefusalOf(static function () use ($route, $stanza, &$returned): void {
        $returned = chokePointRoutes()[$route]($stanza, false);
    });

    expect($refusal)->toBeInstanceOf(NoTenantResolved::class)
        ->and((string) $refusal?->getMessage())->toContain('No tenant has been resolved for this process')
        ->and($returned)->toBeNull()
        ->and(chokePointCachedTenantKeys())->toBe([]);
}

/**
 * AC-23's second column and AC-21's first half: a tenant IS resolved and the
 * stanza is one Tenancy did not write, so the refusal must be
 * TenantConnectionMismatch - "something wrote this behind Tenancy's back", not
 * "you forgot to resolve a tenant".
 *
 * AC-30 rides along: the foreign stanza carries the fixture password, which must
 * reach no part of the exception.
 */
function chokePointAssertForeignStanzaIsRefused(string $route): void
{
    tenancy()->enter('tenant-a');

    try {
        $stanza = chokePointForeignStanza('tenant-b');

        config()->set('database.connections.tenant', $stanza);
        DB::purge('tenant');

        $returned = null;

        $refusal = chokePointRefusalOf(static function () use ($route, $stanza, &$returned): void {
            $returned = chokePointRoutes()[$route]($stanza, false);
        });

        expect($refusal)->toBeInstanceOf(TenantConnectionMismatch::class)
            ->and($refusal)->toBeInstanceOf(TenancyException::class)
            // No row of tenant B came back.
            ->and($returned)->toBeNull()
            // AC-30.
            ->and((string) $refusal?->getMessage())->not->toContain(tenancyFixturePassword())
            ->and((string) $refusal)->not->toContain(tenancyFixturePassword())
            ->and((string) $refusal?->getTraceAsString())->not->toContain(tenancyFixturePassword())
            ->and($refusal?->getPrevious())->toBeNull();
    } finally {
        tenancy()->leave();
    }
}

/**
 * AC-23's third column, and the positive control AC-40 requires for the two
 * above: the same route, the stanza Tenancy wrote, tenant A's own row.
 */
function chokePointAssertAuthenticStanzaSucceeds(string $route): void
{
    tenancy()->enter('tenant-a');

    try {
        $value = chokePointRoutes()[$route](tenancyTenantStanza(), true);

        expect((string) $value)->toBe('tenant-a');
    } finally {
        DB::purge('tenant');
        tenancy()->leave();
    }
}

beforeEach(function () {
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

afterAll(function () {
    // MEASURED, and the reason this line is here rather than in the shared
    // fixture: Event::fake() records every dispatched event, and
    // ConnectionEstablished carries the Connection object - so a faked
    // dispatcher keeps every connection of its test alive inside a reference
    // cycle with the application. Without a collection pass the SQLite handles
    // are still open when the fixture unlinks its files, and on Windows that is
    // a warning plus a directory left behind rather than a clean teardown.
    // Only this file fakes the dispatcher around a tenant connection.
    gc_collect_cycles();

    tenancyDeleteFixtureRoot();
});

it('refuses every construction route when no tenant is resolved', function (string $route) {
    chokePointAssertNoTenantIsRefused($route);
})->with(chokePointRouteNames());

it('refuses every construction route handed a stanza Tenancy did not write', function (string $route) {
    chokePointAssertForeignStanzaIsRefused($route);
})->with(chokePointRouteNames());

it('serves the resolved tenant through every construction route', function (string $route) {
    chokePointAssertAuthenticStanzaSucceeds($route);
})->with(chokePointRouteNames());

/*
 * AC-28. The same three columns with the dispatcher faked. Event::fake() swaps
 * the `events` binding and DatabaseManager resolves it per dispatch, so a guard
 * that depended on ConnectionEstablished would quietly stop running here. Every
 * row must give the same answer as above.
 */

it('refuses every construction route with no tenant resolved under a faked dispatcher', function (string $route) {
    Event::fake();

    chokePointAssertNoTenantIsRefused($route);
})->with(chokePointRouteNames());

it('refuses every construction route handed a foreign stanza under a faked dispatcher', function (string $route) {
    Event::fake();

    chokePointAssertForeignStanzaIsRefused($route);
})->with(chokePointRouteNames());

it('serves the resolved tenant through every construction route under a faked dispatcher', function (string $route) {
    Event::fake();

    chokePointAssertAuthenticStanzaSucceeds($route);
})->with(chokePointRouteNames());

it('refuses a connectUsing connection queried after a tenant switch under a faked dispatcher', function () {
    // AC-27, and the reason FP-160 exists at all. Under the three-attachment
    // design this route was covered by the ConnectionEstablished listener
    // alone, so Event::fake() left it with no per-query guard: the held object
    // repointed itself at whatever tenant was configured next.
    //
    // MEASURED at the T-006 tip: the query returns 'tenant-b'. Because
    // connectUsing() caches under the connection name, entering tenant B purges
    // this object, and reconnectIfMissingConnection() then reopens it against
    // the live stanza - the manager's reconnector is
    // `fn ($c) => $c->setPdo($this->reconnect(...)->getRawPdo())`. So the leak
    // here is a connection built for A silently answering as B rather than
    // refusing. The next case is the same hole in the shape that returns A's own
    // row inside B's scope.
    //
    // connectUsing() rather than build() because build() also writes
    // DatabaseManager::$dynamicConnectionConfigurations['tenant'], which no
    // public API clears and configuration() prefers over config() - so the
    // switch to tenant B would be refused while entering, before any query.
    Event::fake();

    tenancy()->enter('tenant-a');

    $connection = DB::connectUsing('tenant', tenancyTenantStanza(), true);

    expect((string) $connection->scalar('select value from marker'))->toBe('tenant-a');

    tenancy()->leave();
    tenancy()->enter('tenant-b');

    try {
        $served = null;

        $refusal = chokePointRefusalOf(static function () use ($connection, &$served): void {
            $served = (string) $connection->scalar('select value from marker');
        });

        // The row comes first on purpose: it makes the red output name what
        // actually happened instead of only the missing exception.
        expect($served)->toBeNull('the held connection served a row inside another tenant\'s scope')
            ->and($refusal)->toBeInstanceOf(TenantConnectionMismatch::class)
            ->and((string) $refusal?->getMessage())->toContain('built for tenant [tenant-a]');

        // And the switch itself worked: B reads B.
        expect(tenancyMarker())->toBe('tenant-b');
    } finally {
        // purge() rather than $connection->disconnect(): connectUsing() caches
        // the object under the connection name, so this closes the same handle,
        // and connectUsing()'s declared return type is ConnectionInterface,
        // which has no disconnect().
        DB::purge('tenant');
        tenancy()->leave();
    }
});

it('refuses a factory-built connection holding its own handle across a switch under a faked dispatcher', function () {
    // AC-27 in the shape that is a cross-tenant read rather than a repointing:
    // a connection the manager never cached, so no purge() can reach it and its
    // PDO survives the switch intact.
    //
    // MEASURED at the T-006 tip: this returns 'tenant-a' inside tenant B's
    // scope, with TenantContext reporting B and nothing raised. That is the
    // leak the whole task exists to prevent, and under the three-attachment
    // design nothing covered it at all - the extension is name-keyed and never
    // consulted, and the listener is disabled by the fake.
    Event::fake();

    tenancy()->enter('tenant-a');

    $factory = app('db.factory');

    expect($factory)->toBeInstanceOf(ConnectionFactory::class);

    /** @var ConnectionFactory $factory */
    $connection = $factory->make(tenancyTenantStanza(), 'tenant');

    expect((string) $connection->scalar('select value from marker'))->toBe('tenant-a');

    tenancy()->leave();
    tenancy()->enter('tenant-b');

    try {
        $served = null;

        $refusal = chokePointRefusalOf(static function () use ($connection, &$served): void {
            $served = (string) $connection->scalar('select value from marker');
        });

        expect($served)->toBeNull('tenant A\'s own row was served inside tenant B\'s scope')
            ->and($refusal)->toBeInstanceOf(TenantConnectionMismatch::class)
            ->and((string) $refusal?->getMessage())->toContain('built for tenant [tenant-a]');

        expect(tenancyMarker())->toBe('tenant-b');
    } finally {
        $connection->disconnect();
        tenancy()->leave();
    }
});

it('refuses a built connection queried after the tenant scope ends under a faked dispatcher', function () {
    // AC-27's build() half. The switch here is to "no tenant", because a refused
    // or completed build() leaves the tenant name pointing at the caller's array
    // for the rest of the process, so entering a second tenant is refused while
    // entering. "No tenant resolved" is the same hole seen from the other side:
    // the held object must refuse, not reconnect.
    //
    // MEASURED at the T-006 tip: this case is already GREEN there, unlike the
    // connectUsing one above - not because the build() route was guarded, but
    // because leave() purges the name, which nulls the PDO, so the next query
    // goes through reconnectIfMissingConnection() and the manager rebuilds it
    // through makeConnection(), where the name-keyed extension did run. It is
    // therefore a regression guard on an accidental protection, not a red-first
    // criterion; the red-first statement of AC-27 is the case above.
    Event::fake();

    tenancy()->enter('tenant-a');

    $connection = DB::build(['name' => 'tenant'] + tenancyTenantStanza());

    expect((string) $connection->scalar('select value from marker'))->toBe('tenant-a');

    tenancy()->leave();

    try {
        expect(fn (): mixed => $connection->scalar('select value from marker'))
            ->toThrow(NoTenantResolved::class);
    } finally {
        // See the note on the previous case: build() delegates to connectUsing(),
        // so the object is the one cached under the connection name.
        DB::purge('tenant');
    }
});

it('attaches exactly one per-query guard and one per-transaction guard', function () {
    // AC-24. Today there is one of each only because a process-wide WeakMap
    // hides the second attachment; with one choke point there is only ever one
    // attachment to make.
    tenancy()->enter('tenant-a');

    $connection = DB::connection('tenant');

    expect(chokePointHookCount($connection, 'beforeExecutingCallbacks'))->toBe(1)
        ->and(chokePointHookCount($connection, 'beforeStartingTransaction'))->toBe(1);

    // The positive control AC-40 asks for: the one callback in each list is the
    // guard, not a no-op occupying the slot. It passes the query through while
    // the tenant it was built for is resolved, and refuses both a query and a
    // transaction once another tenant is.
    expect((string) $connection->scalar('select value from marker'))->toBe('tenant-a');

    tenancy()->leave();
    tenancy()->enter('tenant-b');

    try {
        expect(fn (): mixed => $connection->scalar('select value from marker'))
            ->toThrow(TenantConnectionMismatch::class);

        expect(fn (): mixed => $connection->transaction(static fn (): int => 1))
            ->toThrow(TenantConnectionMismatch::class);
    } finally {
        $connection->disconnect();
        tenancy()->leave();
    }
});

it('no longer declares the de-duplication map the choke point made unnecessary', function () {
    // AC-25. One attachment point needs no map to tell it whether it has already
    // attached.
    expect(static fn (): ReflectionProperty => new ReflectionProperty(
        TenancyServiceProvider::class,
        'guardedConnections'
    ))->toThrow(ReflectionException::class);
});

it('keeps no static reference to any connection anywhere in the tenancy namespace', function () {
    // AC-25's second half, asserted by running rather than by grepping for
    // "WeakMap": the claim is not that no WeakMap exists under Tenancy/ - one
    // does, CredentialValues, keyed by credential objects - but that no static
    // property of any tenancy class holds a Connection. A live tenant
    // connection is open while the sweep runs, so a map that pinned one would
    // be populated at this moment.
    tenancy()->enter('tenant-a');

    try {
        expect((string) DB::connection('tenant')->scalar('select value from marker'))->toBe('tenant-a');

        $classes = chokePointTenancyClasses();
        $offenders = [];

        foreach ($classes as $class) {
            foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                if (! $property->isInitialized()) {
                    continue;
                }

                foreach (chokePointConnectionsIn($property->getValue()) as $held) {
                    $offenders[] = $class.'::$'.$property->getName().' holds '.$held;
                }
            }
        }

        // The sweep is only evidence if it actually looked at something.
        expect($classes)->toContain(TenancyServiceProvider::class)
            ->and(count($classes))->toBeGreaterThan(8)
            ->and($offenders)->toBe([]);
    } finally {
        tenancy()->leave();
    }
});

it('refers to no connection event anywhere in the tenancy code', function () {
    // AC-28's search half, with the whitespace collapsed first so that a
    // wrapped or reformatted occurrence cannot hide from it.
    $code = chokePointTenancySource(stripComments: true);
    $withComments = chokePointTenancySource();

    expect($code)->not->toBe('')
        ->and($code)->not->toContain('ConnectionEstablished')
        // Two controls, because "the needle was not found" is worthless without
        // them. First: the search does find something that is genuinely in the
        // code, so it is looking in the right place.
        ->and($code)->toContain('beforeStartingTransaction')
        // Second: the needle IS present in the files, in the docblocks that
        // record what FP-160 removed. So this assertion is specifically about
        // the absence of a code reference, and the comment stripping - not an
        // empty haystack or a misspelt needle - is what makes it pass.
        ->and($withComments)->toContain('ConnectionEstablished');
});

it('is the very factory the resolved database manager was constructed with', function () {
    // R7's first installation invariant. The decoration is registered in
    // register(), before anything can resolve `db`; if that ordering ever broke,
    // DatabaseManager would keep the undecorated factory it was injected with
    // and every assertion above would pass for the wrong reason.
    $manager = app('db');

    expect($manager)->toBeInstanceOf(DatabaseManager::class);

    $injected = (new ReflectionProperty(DatabaseManager::class, 'factory'))->getValue($manager);

    expect($injected)->toBeInstanceOf(TenantConnectionFactory::class);
});

it('registers no name-keyed connection extension for the tenant connection', function () {
    // R7's second installation invariant. DatabaseManager::makeConnection()
    // consults $extensions[$name] BEFORE the factory, so anything registered
    // under the tenant connection name would bypass the choke point entirely.
    // This is a standing assertion about the whole application, not only about
    // tenancy's own registrations.
    tenancy()->run('tenant-a', function (): void {
        expect((string) DB::connection('tenant')->scalar('select value from marker'))->toBe('tenant-a');
    });

    $extensions = (new ReflectionProperty(DatabaseManager::class, 'extensions'))->getValue(app('db'));

    expect(array_keys((array) $extensions))->not->toContain('tenant');
});

it('delegates to the factory it replaced rather than to its own parent', function () {
    // Why this matters: TenantConnectionFactory has to extend ConnectionFactory,
    // because DatabaseManager::__construct type-hints it - and that makes
    // parent::make() look like the obvious way to build the connection. It is
    // not: calling it would silently discard any other extender of 'db.factory'
    // that this decoration wrapped.
    $inner = new class(app()) extends ConnectionFactory
    {
        public int $calls = 0;

        /**
         * @param  array<string, mixed>  $config
         * @param  string|null  $name
         */
        public function make(array $config, $name = null): Connection
        {
            $this->calls++;

            return parent::make($config, $name);
        }
    };

    $factory = new TenantConnectionFactory(app(), $inner);

    // A connection that is not the tenant one passes straight through.
    $master = $factory->make((array) config('database.connections.master'), 'master');

    expect($master)->toBeInstanceOf(Connection::class)
        ->and($inner->calls)->toBe(1);

    $master->disconnect();

    // And so does the tenant connection, once the stanza has been vouched for.
    tenancy()->run('tenant-a', function () use ($factory, $inner): void {
        $connection = $factory->make(tenancyTenantStanza(), 'tenant');

        try {
            expect((string) $connection->scalar('select value from marker'))->toBe('tenant-a')
                ->and($inner->calls)->toBe(2);
        } finally {
            // Neither connection here is in the manager, so nothing else closes
            // them; an open SQLite handle breaks the fixture's afterAll unlink()
            // on Windows.
            $connection->disconnect();
        }
    });
});

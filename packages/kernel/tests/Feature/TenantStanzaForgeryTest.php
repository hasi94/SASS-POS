<?php

use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionAuthority;
use Flexipos\Kernel\Tenancy\TenantModel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
 * T-007 FP-161: the tenant stanza cannot be forged.
 *
 * The forger model is decision D-6:
 *
 *   F1 knows the published source, including every constant.
 *   F2 runs inside tenant A's scope, can call every public method
 *      (Tenancy::current(), TenantContext, config(), DB) and can READ the
 *      current stanza.
 *   F3 reflects into the live scoped object or rebuilds the container. Out of
 *      scope by decision, recorded as the residual (AC-22).
 *
 * The mechanism this file holds to account is a per-enter() random token bound,
 * through a private record on a scoped authority, to a digest of the whole
 * stanza. Why content and not identity: a token stored only in the stanza, and a
 * sentinel object held in a private WeakMap, both FAIL AC-14, and for the same
 * reason - an array copy carries every value and the same object instance along
 * with it, so anything that can read the stanza can reproduce it. Binding to the
 * content is what makes a changed database detectable (plan R1, measured).
 *
 * Covers AC-13, AC-14, AC-15, AC-16, AC-19, AC-20, AC-21, AC-22 and AC-39.
 *
 * The fixture helpers come from ../Support/tenancy-fixture.php, required below
 * rather than borrowed from whichever file PHPUnit happened to collect first
 * (T-004 FP-164). The route table below is this file's own, for the same
 * reason: a shared one would make running this file alone depend on another
 * file having been collected.
 */

require_once __DIR__.'/../Support/tenancy-fixture.php';

uses(TestCase::class);

/**
 * An Eloquent model on the shared tenant connection.
 */
final class ForgeryProbeModel extends TenantModel
{
    public $timestamps = false;

    protected $table = 'marker';
}

/**
 * The key the stanza carries its authenticator under.
 *
 * A literal, on purpose: the constant is private to TenantConnectionAuthority,
 * because the whole point of FP-161 is that no published name authenticates
 * anything. A test that read the constant would be asserting against the
 * implementation instead of against the published surface.
 */
function forgeryAuthenticatorKey(): string
{
    return 'flexipos_tenant_authenticator';
}

function forgeryRefusalOf(Closure $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * Every string an F1 forger can read out of the published source and try as a
 * stanza key: the value of every public constant of every class under
 * packages/kernel/src/Tenancy/, plus the two literal key names this project has
 * ever used.
 *
 * Derived by reflection rather than listed, so deleting a constant does not
 * quietly narrow what the forgery attempts - which is exactly how AC-20's
 * "search result alone is not accepted" is honoured.
 *
 * @return list<string>
 */
function forgeryPublishedStanzaKeys(): array
{
    $keys = ['flexipos_tenant', forgeryAuthenticatorKey()];

    foreach (forgeryTenancyClasses() as $class) {
        foreach ((new ReflectionClass($class))->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $value = $constant->getValue();

            if (is_string($value) && $value !== '') {
                $keys[] = $value;
            }
        }
    }

    return array_values(array_unique($keys));
}

/**
 * @return list<string>
 */
function forgeryTenancyClasses(): array
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
 * A complete, driver-ready stanza pointing at tenant B's real database, written
 * from nothing but published knowledge, with the given authenticator candidates
 * merged in.
 *
 * It carries the fixture password as a plain string so AC-30 has a needle to
 * look for in every refusal this stanza produces.
 *
 * @param  array<string, mixed>  $authenticators
 * @return array<string, mixed>
 */
function forgeryPublishedStanza(array $authenticators): array
{
    return array_merge([
        'driver' => 'sqlite',
        'host' => 'forgery-published',
        'port' => 3306,
        'database' => tenancyFixtureDatabasePath('tenant-b'),
        'username' => 'forgery_published_app',
        'password' => tenancyFixturePassword(),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'options' => [],
    ], $authenticators);
}

/**
 * @param  array<string, mixed>  $stanza
 * @return array<string, mixed>
 */
function forgerySplitStanza(array $stanza): array
{
    $database = $stanza['database'] ?? '';

    return array_merge($stanza, [
        'read' => ['database' => $database],
        'write' => ['database' => $database],
    ]);
}

/**
 * The forgeries, each as a closure handed the genuine stanza and returning the
 * one to be refused.
 *
 * @return array<string, Closure(array<string, mixed>): array<string, mixed>>
 */
function forgeryStanzas(): array
{
    return [
        // AC-13 (F1). Nothing is copied from the live stanza: the forger writes
        // one from the published source and sets every key it could find to the
        // resolved tenant id, which is the one secret-looking value the public
        // surface really does hand out (TenantContext::tenantId()).
        'F1 every published constant set to the resolved tenant id' => static function (array $genuine): array {
            $tenantId = tenancy()->current()->tenantId();
            $authenticators = [];

            foreach (forgeryPublishedStanzaKeys() as $key) {
                $authenticators[$key] = $tenantId;
            }

            return forgeryPublishedStanza($authenticators);
        },

        // AC-13 (F1), knowing the mechanism and not merely the constants: a
        // token of exactly the right shape, minted the same way.
        'F1 a token of the mechanism own shape' => static fn (array $genuine): array => forgeryPublishedStanza([
            forgeryAuthenticatorKey() => bin2hex(random_bytes(16)),
        ]),

        // AC-14 (F2). Every value copied, including whatever Tenancy put there,
        // and exactly one thing changed - each of these redirects which
        // database is opened.
        'F2 copies every value and changes the database' => static fn (array $genuine): array => array_merge($genuine, [
            'database' => tenancyFixtureDatabasePath('tenant-b'),
        ]),

        'F2 copies every value and changes the host' => static fn (array $genuine): array => array_merge($genuine, [
            'host' => 'forgery-elsewhere',
        ]),

        'F2 copies every value and changes the username' => static fn (array $genuine): array => array_merge($genuine, [
            'username' => 'forgery_other_app',
        ]),

        // S-5. Not a redirection at all - `prefix` decides nothing about which
        // database is opened - and refused anyway, which is the point: it is
        // the other key ConnectionFactory::parseConfig() writes, and the only
        // plausible candidate for a third digest exclusion. A refusal here is
        // what makes "two keys, not three" behaviour. MEASURED by mutation:
        // add `prefix` to the exclusion list and six of this row's seven route
        // cases go red - five of them serving tenant A's row, the Eloquent one
        // on the forged table prefix - while the read/write route stays green,
        // because that row's stanza also carries the injected split and is
        // refused for it. The pin does not rest on that route.
        'F2 copies every value and changes only the prefix' => static fn (array $genuine): array => array_merge($genuine, [
            'prefix' => 'forgery_',
        ]),

        // The key a whitelist of "redirecting" keys would have had to know
        // about: ConnectionFactory::make() branches to a read/write connection
        // on isset($config['read']) alone.
        'F2 copies every value and injects a read split' => static fn (array $genuine): array => array_merge($genuine, [
            'read' => ['database' => tenancyFixtureDatabasePath('tenant-b')],
            'write' => ['database' => tenancyFixtureDatabasePath('tenant-b')],
        ]),
    ];
}

/**
 * @return list<string>
 */
function forgeryStanzaNames(): array
{
    return array_keys(forgeryStanzas());
}

/**
 * AC-23's route list again, each closure running one query through the tenant
 * connection its own way. The second argument says whether the stanza is the
 * one Tenancy wrote; only the read/write row needs it, because Tenancy never
 * writes a split and injecting one is itself a forgery.
 *
 * @return array<string, Closure(array<string, mixed>, bool): mixed>
 */
function forgeryRoutes(): array
{
    return [
        'DB::connection' => static fn (array $stanza, bool $genuine): mixed => DB::connection('tenant')
            ->scalar('select value from marker'),

        'an Eloquent model' => static fn (array $stanza, bool $genuine): mixed => ForgeryProbeModel::query()
            ->value('value'),

        'DB::build' => static fn (array $stanza, bool $genuine): mixed => DB::build(['name' => 'tenant'] + $stanza)
            ->scalar('select value from marker'),

        'DB::connectUsing' => static fn (array $stanza, bool $genuine): mixed => DB::connectUsing('tenant', $stanza, true)
            ->scalar('select value from marker'),

        'the connection factory directly' => static function (array $stanza, bool $genuine): mixed {
            $connection = app('db.factory')->make($stanza, 'tenant');

            try {
                return $connection->scalar('select value from marker');
            } finally {
                // Nothing caches this one, so no purge() reaches it and the
                // SQLite handle would outlive the test.
                $connection->disconnect();
            }
        },

        'a reconnect after purge' => static function (array $stanza, bool $genuine): mixed {
            DB::purge('tenant');

            return DB::reconnect('tenant')->scalar('select value from marker');
        },

        'a read/write split' => static function (array $stanza, bool $genuine): mixed {
            if (! $genuine) {
                config()->set('database.connections.tenant', forgerySplitStanza($stanza));
                DB::purge('tenant');
            }

            return DB::connection('tenant::read')->scalar('select value from marker');
        },
    ];
}

/**
 * @return list<string>
 */
function forgeryRouteNames(): array
{
    return array_keys(forgeryRoutes());
}

/**
 * The genuine stanza's authenticator, read from the one place it is published -
 * the stanza itself, which F2 may read.
 */
function forgeryCurrentToken(): string
{
    $token = tenancyTenantStanza()[forgeryAuthenticatorKey()] ?? null;

    return is_string($token) ? $token : '';
}

beforeEach(function () {
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

afterAll(function () {
    tenancyDeleteFixtureRoot();
});

it('refuses every forged stanza on every construction route', function (string $route, string $forgery) {
    // AC-13, AC-14 and AC-15 in one table: each forgery against each route.
    // AC-21's first class and AC-30 ride along, because the forgery happens
    // while tenant A IS resolved and the forged stanza carries a password.
    tenancy()->enter('tenant-a');

    try {
        $forged = forgeryStanzas()[$forgery](tenancyTenantStanza());

        config()->set('database.connections.tenant', $forged);
        DB::purge('tenant');

        $returned = null;

        $refusal = forgeryRefusalOf(static function () use ($route, $forged, &$returned): void {
            $returned = forgeryRoutes()[$route]($forged, false);
        });

        expect($returned)->toBeNull('the forged stanza served a row')
            ->and($returned)->not->toBe('tenant-b')
            // AC-21: a tenant IS resolved, so this is "something wrote that
            // stanza behind Tenancy's back", not "you forgot Tenancy::run()".
            ->and($refusal)->toBeInstanceOf(TenantConnectionMismatch::class)
            ->and($refusal)->toBeInstanceOf(TenancyException::class)
            ->and((string) $refusal?->getMessage())->toContain('only code allowed to write that configuration')
            // AC-30.
            ->and((string) $refusal?->getMessage())->not->toContain(tenancyFixturePassword())
            ->and((string) $refusal)->not->toContain(tenancyFixturePassword())
            ->and((string) $refusal?->getTraceAsString())->not->toContain(tenancyFixturePassword())
            ->and($refusal?->getPrevious())->toBeNull();
    } finally {
        tenancy()->leave();
    }
})->with(forgeryRouteNames())->with(forgeryStanzaNames());

it('serves tenant A through every route when the stanza is left exactly as Tenancy wrote it', function (string $route) {
    // AC-39's control for the table above, and AC-17's shape. Identical set-up,
    // nothing mutated: if this went red the refusals would prove only that the
    // harness breaks connections, not that it detects forgeries.
    tenancy()->enter('tenant-a');

    try {
        $value = forgeryRoutes()[$route](tenancyTenantStanza(), true);

        expect((string) $value)->toBe('tenant-a');
    } finally {
        DB::purge('tenant');
        tenancy()->leave();
    }
})->with(forgeryRouteNames());

it('refuses a forged stanza with NoTenantResolved when no tenant is resolved at all', function (string $forgery) {
    // AC-21's second class. The same forgeries, outside any scope: the answer
    // must name the missing tenant rather than a mismatch, because there is no
    // resolved tenant for a stanza to disagree with.
    //
    // F2's rows need a genuine stanza to copy, so the forgery is built inside a
    // scope and then used outside it - which is also the "stanza retained after
    // the scope ended" shape.
    tenancy()->enter('tenant-a');
    $forged = forgeryStanzas()[$forgery](tenancyTenantStanza());
    tenancy()->leave();

    config()->set('database.connections.tenant', $forged);
    DB::purge('tenant');

    $returned = null;

    $refusal = forgeryRefusalOf(static function () use (&$returned): void {
        $returned = DB::connection('tenant')->scalar('select value from marker');
    });

    expect($returned)->toBeNull()
        ->and($refusal)->toBeInstanceOf(NoTenantResolved::class);
})->with(forgeryStanzaNames());

it('mints a different authenticator for each enter, and refuses the previous scope value', function () {
    // AC-19, answered "yes, per enter()". Two scopes for the SAME tenant, so a
    // value captured in one is useless in the next - which is what makes the
    // window a disclosure opens exactly one scope wide.
    tenancy()->enter('tenant-a');
    $first = forgeryCurrentToken();
    $firstStanza = tenancyTenantStanza();
    tenancy()->leave();

    tenancy()->enter('tenant-a');

    try {
        $second = forgeryCurrentToken();

        expect($first)->not->toBe('')
            ->and($second)->not->toBe('')
            ->and($second)->not->toBe($first)
            // Not the tenant id, and not derivable from it: the thing AC-13's
            // F1 row tries and fails to compute.
            ->and($first)->not->toBe('tenant-a')
            ->and($first)->not->toContain('tenant-a');

        // The first scope's whole stanza, token included, inside the second
        // scope for the same tenant.
        config()->set('database.connections.tenant', $firstStanza);
        DB::purge('tenant');

        expect(fn (): mixed => DB::connection('tenant')->scalar('select value from marker'))
            ->toThrow(TenantConnectionMismatch::class);
    } finally {
        tenancy()->leave();
    }
});

it('publishes the authenticator nowhere but the stanza it authenticates', function () {
    // AC-16. The stanza itself is excluded by the criterion - F2 may read it,
    // and AC-14 is what covers that - so what is checked here is the context,
    // the public accessor, every refusal message a guard raises, and the log
    // records it writes.
    tenancy()->enter('tenant-a');

    // Declared before the try, so the teardown can close it whichever
    // assertion inside fails first.
    $held = null;

    try {
        $token = forgeryCurrentToken();

        expect($token)->not->toBe('');

        // The control first, while the record is still live, because the
        // absences below mean nothing without it: a misspelt needle or a token
        // that was never minted would produce the same empty findings array.
        //
        // These are the two places the token IS. The authority is one by
        // construction - a record has to be keyed by something - and that is
        // F3's surface, undefended by decision D-6 and written down in the
        // class's own docblock. The stanza is the other, and AC-16 excludes it
        // on purpose: F2 may read it, and AC-14 is what covers that.
        expect(print_r(app(TenantConnectionAuthority::class), true))->toContain($token)
            ->and(print_r(tenancyTenantStanza(), true))->toContain($token);

        // Called directly, never through a helper closure: PHP renders a
        // closure's captured variables, so a harness that passed the needle
        // through one would report a leak of its own making.
        $context = tenancy()->current();

        $surfaces = [
            'TenantContext' => print_r($context, true),
            'Tenancy::current()' => print_r(tenancy()->current(), true),
            'the context as an array' => print_r((array) $context, true),
            'the resolved mapping record' => print_r($context->database(), true),
        ];

        // Every refusal a guard raises while a tenant is resolved, plus the
        // stale-connection one, collected as strings.
        $held = DB::connection('tenant');

        config()->set('database.connections.tenant', forgeryPublishedStanza([]));
        DB::purge('tenant');

        $surfaces['the unauthorised-stanza refusal'] = (string) forgeryRefusalOf(static function (): void {
            DB::connection('tenant')->scalar('select value from marker');
        });

        tenancy()->leave();
        tenancy()->enter('tenant-b');

        $surfaces['the stale-connection refusal'] = (string) forgeryRefusalOf(static function () use ($held): void {
            $held->scalar('select value from marker');
        });

        // The log records the guard writes: leaving a scope with an open
        // transaction is the one path that logs at all.
        $records = [];

        Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$records): void {
            $records[] = $logged->level.' '.$logged->message.' '.(string) json_encode($logged->context);
        });

        DB::connection('tenant')->beginTransaction();
        tenancy()->leave();

        $surfaces['the log records'] = implode("\n", $records);

        expect($records)->not->toBe([]);

        // Collected and asserted as one array rather than looped over with a
        // negated toContain(): that assertion takes a variadic list of needles,
        // so a second argument meant as a message silently becomes a second
        // needle and the negation then passes when EITHER is absent. The host's
        // QaAssertionShapeGuardTest caught exactly that here.
        $findings = [];

        foreach ($surfaces as $where => $rendered) {
            if ($rendered === '') {
                $findings[] = $where.' rendered nothing, so it proves nothing';
            }

            if (str_contains($rendered, $token)) {
                $findings[] = 'the authenticator appeared in '.$where;
            }
        }

        expect($findings)->toBe([]);
    } finally {
        $held?->disconnect();
        tenancy()->leave();
    }
});

it('lets the connection name change, and nothing else, without breaking the binding', function () {
    // The one key the digest excludes, stated as behaviour rather than left in
    // a docblock. It has to be excluded: DatabaseManager::build() writes `name`
    // into the stanza whether the caller supplied one or not - the key is
    // defaulted (`$config['name'] ??= static::calculateDynamicConnectionName()`)
    // and that same stanza is handed to connectUsing() and so to this factory -
    // so with `name` inside the digest AC-23's third column could never pass for
    // that route, named or unnamed. The framework half is pinned by
    // TenancyFrameworkContractTest, under "still writes a connection name into
    // the stanza build() hands the factory", which is where a vendor change
    // reddens; this case is the tenancy half. Cited by symbol rather than by
    // line, because the line moves between patch releases and the assertion
    // does not.
    //
    // This comment used to say the framework makes the caller supply `name`. It
    // does not - that is the claim 78ae627 corrected in
    // TenantConnectionAuthority::digest(), and the correction landed in the
    // class without reaching the evidence beside it, which is exactly FP-176's
    // shape. Written out rather than quietly swapped, so the next reader knows
    // the conclusion survived the reason being wrong.
    //
    // And the residual of excluding it, measured rather than asserted away
    // (plan R7): a genuine stanza rebuilt under another connection name does
    // build - and reaches only the forger's own tenant, is guarded per query
    // like anything else, and is refused the moment the scope ends. What it
    // escapes is the name-based teardown, which is FP-163's problem.
    tenancy()->enter('tenant-a');

    $elsewhere = DB::build(['name' => 'forgery-elsewhere'] + tenancyTenantStanza());

    try {
        expect((string) $elsewhere->scalar('select value from marker'))->toBe('tenant-a');

        // Changing anything else in the same breath is refused, so the
        // exclusion really is one key wide.
        expect(function (): void {
            DB::build(['name' => 'forgery-elsewhere-b', 'database' => tenancyFixtureDatabasePath('tenant-b')]
                + tenancyTenantStanza())->scalar('select value from marker');
        })->toThrow(TenantConnectionMismatch::class);
    } finally {
        tenancy()->leave();
    }

    // The scope is over, and the per-query guard is what makes the residual
    // survivable: the escaped name does not outlive its tenant.
    expect(fn (): mixed => $elsewhere->scalar('select value from marker'))
        ->toThrow(NoTenantResolved::class);

    DB::purge('forgery-elsewhere');
});

it('serves a stanza that adds only `name`, the lower bound of the exclusion list', function () {
    // S-5's second half, and the pair to the "changes only the prefix" forgery
    // row above: add one key and nothing else, on the plain config route rather
    // than through DB::build(), and the connection is served. Between the two,
    // the exclusion list is pinned at exactly two keys by behaviour - this case
    // reddens if `name` ever leaves it, the forgery row reddens if a third key
    // ever joins it. Neither can be satisfied by a comment.
    tenancy()->enter('tenant-a');

    try {
        config()->set('database.connections.tenant', [
            'name' => 'forgery-named-in-config',
        ] + tenancyTenantStanza());

        DB::purge('tenant');

        expect((string) DB::connection('tenant')->scalar('select value from marker'))->toBe('tenant-a');
    } finally {
        DB::purge('tenant');
        tenancy()->leave();
    }
});

it('records two strings per authorisation and nothing of the stanza, and empties on revoke', function () {
    // The storage claim the AC-22 docblock makes, asserted rather than
    // asserted-by-comment: the authority holds the tenant id and a digest, so
    // no credential object and no stanza survives in it, and the record is gone
    // once the scope is.
    $records = new ReflectionProperty(TenantConnectionAuthority::class, 'records');

    tenancy()->enter('tenant-a');

    $authority = app(TenantConnectionAuthority::class);

    try {
        $live = (array) $records->getValue($authority);

        expect($live)->toHaveCount(1);

        foreach ($live as $token => $record) {
            $record = (array) $record;

            expect($token)->toBeString()
                ->and(array_keys($record))->toBe(['tenant', 'digest'])
                ->and($record['tenant'])->toBe('tenant-a')
                // Two strings and nothing else: no stanza, and therefore no
                // ConnectionPassword held past the scope.
                ->and(array_filter($record, 'is_string'))->toHaveCount(2);
        }
    } finally {
        tenancy()->leave();
    }

    expect($records->getValue($authority))->toBe([]);
});

it('publishes no constant whose value alone authenticates a stanza', function () {
    // AC-20. Two halves, and the criterion says the search alone is not enough -
    // the F1 rows of the table above are the other half.
    expect(static fn (): ReflectionClassConstant => new ReflectionClassConstant(Tenancy::class, 'TENANT_MARKER'))
        ->toThrow(ReflectionException::class);

    $published = [];

    foreach (forgeryTenancyClasses() as $class) {
        foreach ((new ReflectionClass($class))->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $published[] = $class.'::'.$constant->getName();
        }
    }

    // The sweep looked at something, and nothing named like the old marker
    // survives anywhere under packages/ or modules/.
    expect(count(forgeryTenancyClasses()))->toBeGreaterThan(8)
        ->and($published)->not->toBe([])
        ->and(implode(' ', $published))->not->toContain('TENANT_MARKER')
        ->and(forgerySourceSearch('TENANT_MARKER'))->toBe([]);
});

it('writes the undefended residuals down beside the mechanism', function () {
    // AC-22, by reading - made executable, because a residual that only lives
    // in a review comment is a residual nobody will find.
    $docblock = (string) (new ReflectionClass(TenantConnectionAuthority::class))->getDocComment();

    expect($docblock)->not->toBe('')
        // F3 is not defended, by decision D-6.
        ->and($docblock)->toContain('F3')
        // And the two criteria that are the proof of F1 and F2.
        ->and($docblock)->toContain('AC-13')
        ->and($docblock)->toContain('AC-14')
        // The real floor under the whole guarantee: the mapping source.
        ->and($docblock)->toContain('TenantDatabaseRepository');
});

/**
 * Every first-party PHP or stub file under packages/ and modules/ containing the
 * given needle, with vendor/, storage/, node_modules/ and bootstrap/ pruned
 * rather than filtered afterwards.
 *
 * @return list<string>
 */
function forgerySourceSearch(string $needle): array
{
    $repository = dirname(__DIR__, 4);
    $skipped = ['vendor', 'storage', 'node_modules', 'bootstrap'];
    $hits = [];

    foreach ([$repository.DIRECTORY_SEPARATOR.'packages', $repository.DIRECTORY_SEPARATOR.'modules'] as $root) {
        $pruned = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
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

            // This file names the needle on purpose, in the assertion that
            // looks for it and in the comment explaining why.
            if (str_ends_with($normalised, '/TenantStanzaForgeryTest.php')) {
                continue;
            }

            if (str_contains((string) file_get_contents((string) $path), $needle)) {
                $hits[] = $normalised;
            }
        }
    }

    return $hits;
}

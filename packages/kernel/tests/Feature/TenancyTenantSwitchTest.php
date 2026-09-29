<?php

use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * AC-16, AC-17, AC-18 - the single most important file in T-003.
 *
 * One process, two tenants in turn: exactly the shape of a queue worker handling
 * tenant A's job and then tenant B's. A bug here is not a failing feature, it is
 * one shop reading another shop's sales.
 *
 * The fixture helpers come from TenancyResolutionTest.php in this package.
 */

uses(TestCase::class);

beforeEach(function () {
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

it('serves the second tenant from the second database, and closes the first', function () {
    tenancy()->enter('tenant-a');

    // Plan step 13.2: the query below opens a real connection, and the connector
    // passes the stanza's password straight to `new PDO(...)`. Asserting the
    // instance right next to the query turns incidental coverage of the
    // Stringable coercion into intentional coverage: if the framework ever types
    // that parameter or adds strict_types, this pairing is what goes red, and it
    // fails closed - nothing connects - rather than quietly leaking again.
    expect(tenancyTenantStanza()['password'])->toBeInstanceOf(ConnectionPassword::class)
        ->and(tenancyMarker())->toBe('tenant-a');

    $connectionA = DB::connection('tenant');
    $pdoA = $connectionA->getPdo();

    tenancy()->leave();
    tenancy()->enter('tenant-b');

    try {
        // AC-16: the next query reaches B's database, and the context agrees.
        // The second tenant opens its own real connection through the same
        // coercion boundary (step 13.2).
        expect(tenancyTenantStanza()['password'])->toBeInstanceOf(ConnectionPassword::class)
            ->and(tenancyMarker())->toBe('tenant-b')
            ->and(tenancy()->current()->tenantId())->toBe('tenant-b');

        // AC-17: served by a genuinely different connection and a genuinely
        // different PDO, not by A's object reconnected.
        expect(DB::connection('tenant'))->not->toBe($connectionA)
            ->and(DB::connection('tenant')->getPdo())->not->toBe($pdoA);

        // And A's PDO was actually closed, not merely dropped from the manager.
        // getRawPdo() is the accessor that does not reconnect.
        expect($connectionA->getRawPdo())->toBeNull();
    } finally {
        tenancy()->leave();
    }
});

it('leaves nothing of the first tenant in the connection configuration', function () {
    // What entering B looks like from a clean state - the "resolving B alone"
    // baseline AC-17 is measured against.
    tenancy()->enter('tenant-b');
    $fromClean = tenancyComparableStanza();
    tenancy()->leave();

    tenancy()->enter('tenant-a');
    tenancy()->leave();
    tenancy()->enter('tenant-b');

    try {
        // AC-17, with toBe(): not merely "B's values are right" but "there is
        // no extra key and no leftover value from A anywhere in the stanza".
        //
        // The credential is compared by type rather than by value, because it is
        // now a ConnectionPassword object and two of them can never be identical.
        // Every other key is still compared by value, the marker itself is
        // asserted below so the projection cannot silently stop meaning
        // anything, and the instance is asserted separately.
        expect(tenancyComparableStanza())->toBe($fromClean)
            ->and($fromClean['password'])->toBe('password:'.ConnectionPassword::class)
            ->and(tenancyTenantStanza()['password'])->toBeInstanceOf(ConnectionPassword::class)
            ->and(tenancyTenantStanza()[Tenancy::TENANT_MARKER])->toBe('tenant-b');
    } finally {
        tenancy()->leave();
    }
});

it('refuses a query on a connection object held across a tenant switch', function () {
    // THE assertion this task exists for, and the only one that fails if the
    // per-query guard in TenancyServiceProvider is ever removed for performance.
    //
    // DB::purge() drops the manager's reference, but any repository or service
    // that cached DB::connection('tenant') in a property still holds a live
    // object which would happily reconnect through
    // reconnectIfMissingConnection() and serve tenant A's rows to tenant B.
    tenancy()->enter('tenant-a');

    $connectionA = DB::connection('tenant');

    expect((string) $connectionA->scalar('select value from marker'))->toBe('tenant-a');

    tenancy()->leave();
    tenancy()->enter('tenant-b');

    try {
        expect(fn (): mixed => $connectionA->scalar('select value from marker'))
            ->toThrow(TenantConnectionMismatch::class, 'built for tenant [tenant-a]');

        // The transaction hook is guarded too, because a transaction opens
        // without executing a statement of its own first.
        //
        // Connection::beginTransaction() runs the beforeStartingTransaction
        // hooks *before* calling createTransaction(), which is where the
        // reconnect lives - so the guard refuses while the PDO is still null and
        // no handle is reopened. (An earlier comment here claimed the reverse,
        // from reading createTransaction() in isolation, and added a defensive
        // disconnect() in this block for a file lock that never happened.
        // Re-measured for review R-4: removing it changes nothing.)
        expect(fn (): mixed => $connectionA->transaction(fn (): int => 1))
            ->toThrow(TenantConnectionMismatch::class, 'tenant-b');

        expect($connectionA->getRawPdo())->toBeNull();
    } finally {
        tenancy()->leave();
    }
});

it('purges the read and write variants, not just the base connection name', function () {
    // Review R-2. DatabaseManager caches a read/write-suffixed connection under
    // that exact key ("tenant::read"), and purge() unsets only the key it is
    // given - so purging the base name alone left a live handle on the previous
    // shop's database inside the manager itself, not merely in someone's
    // property. The per-query guard refuses queries on it, but an open handle
    // that outlives its tenant scope is not something to leave to a second line
    // of defence, and it becomes a real leak when FP-203 adds read replicas.
    tenancy()->enter('tenant-a');

    expect((string) DB::connection('tenant::read')->scalar('select value from marker'))->toBe('tenant-a')
        ->and(array_keys(DB::getConnections()))->toContain('tenant::read');

    tenancy()->leave();

    $surviving = array_values(array_filter(
        array_map(strval(...), array_keys(DB::getConnections())),
        static fn (string $cached): bool => $cached === 'tenant' || str_starts_with($cached, 'tenant::')
    ));

    expect($surviving)->toBe([], 'these tenant connections outlived the scope: '.implode(', ', $surviving));
});

it('returns to the no-tenant-resolved state when a tenant is left with no replacement', function () {
    tenancy()->enter('tenant-a');

    expect(tenancyMarker())->toBe('tenant-a');

    tenancy()->leave();

    // AC-18: loud again, and the stanza is back to the placeholder.
    expect(tenancy()->current()->hasTenant())->toBeFalse()
        ->and(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());

    expect(fn (): mixed => DB::connection('tenant')->scalar('select value from marker'))
        ->toThrow(NoTenantResolved::class);
});

it('keeps no hidden connection configuration that survives a purge', function () {
    // The DatabaseManager::build()/connectUsing() regression guard.
    //
    // build() stores the whole configuration - password included - in the
    // private DatabaseManager::$dynamicConnectionConfigurations array;
    // configuration() prefers that array over config('database.connections.*');
    // and purge() never clears it, with no public API that can. If anyone ever
    // "simplifies" Tenancy to use it, this test is what goes red: after a full
    // resolve-query-leave cycle, asking for the tenant connection would rebuild
    // the previous tenant's connection instead of refusing.
    tenancy()->run('tenant-a', function (): void {
        expect(tenancyMarker())->toBe('tenant-a');
    });

    expect(fn (): mixed => DB::connection('tenant')->scalar('select value from marker'))
        ->toThrow(NoTenantResolved::class);

    expect(array_keys(DB::getConnections()))->not->toContain('tenant')
        ->and(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());

    // The discriminating assertion, and the reason this test reaches into a
    // protected framework property: the retained configuration is invisible by
    // design, so the only way to prove it is empty is to look. The moment anyone
    // switches Tenancy to build()/connectUsing(), this array holds the previous
    // tenant's configuration - password included - and no public API can clear
    // it again.
    expect(property_exists(DatabaseManager::class, 'dynamicConnectionConfigurations'))->toBeTrue();

    $retained = new ReflectionProperty(DatabaseManager::class, 'dynamicConnectionConfigurations');

    expect($retained->getValue(app(DatabaseManager::class)))->toBe([]);
});

it('handles two tenants in turn through run(), the queue worker shape', function () {
    $seen = [];

    foreach (['tenant-a', 'tenant-b', 'tenant-a'] as $tenantId) {
        $seen[] = tenancy()->run($tenantId, fn (): string => tenancyMarker());
    }

    expect($seen)->toBe(['tenant-a', 'tenant-b', 'tenant-a']);
});

it('refuses to serve a leaked tenant scope after the container scope is reset', function () {
    // What QueueServiceProvider's $resetScope does between jobs. A job that
    // forgot to leave its tenant scope leaves the stanza behind *and* a cached
    // Connection object in the manager; the fresh TenantContext must make both
    // useless rather than let the next job inherit tenant A.
    tenancy()->enter('tenant-a');

    $leaked = DB::connection('tenant');

    expect((string) $leaked->scalar('select value from marker'))->toBe('tenant-a');

    app()->forgetScopedInstances();

    // The object the previous scope handed out: refused by the per-query guard.
    expect(fn (): mixed => $leaked->scalar('select value from marker'))
        ->toThrow(NoTenantResolved::class);

    // And the manager's own cached instance, reached by name: same refusal.
    expect(fn (): mixed => DB::connection('tenant')->scalar('select value from marker'))
        ->toThrow(NoTenantResolved::class);

});

it('never configures a persistent PDO connection for a tenant', function () {
    // docs/database.md section 2, rule 4: a pooled connection is bound to one
    // tenant's database user, so persistence is a direct route to the leak
    // AC-16/AC-17 forbid.
    tenancy()->run('tenant-a', function (): void {
        $options = (array) tenancyTenantStanza()['options'];

        expect($options)->toBe([])
            ->and($options)->not->toHaveKey(PDO::ATTR_PERSISTENT);
    });
});

it('keeps the master connection identical whether or not a tenant is resolved', function () {
    // AC-1's last clause. Resolving a tenant must not touch master at all.
    $before = (array) config('database.connections.master');

    tenancy()->run('tenant-a', function () use ($before): void {
        expect((array) config('database.connections.master'))->toBe($before)
            ->and(DB::connection('master')->scalar('select 1 + 1'))->toEqual(2);
    });

    expect((array) config('database.connections.master'))->toBe($before);
});

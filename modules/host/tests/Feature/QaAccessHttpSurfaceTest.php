<?php

use App\Http\Middleware\RequireTenantPermission;
use App\Http\Middleware\RequireTenantToken;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\ExpectationFailedException;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-010 step 20 - the HTTP surface, the guard and the route sweep
|--------------------------------------------------------------------------
|
| AC-63, AC-64, AC-65 and AC-69's denial half.
|
| **The sweep enumerates from the router, never from a list here.** A list
| in a test is a second declaration of what the application exposes, and the
| day a route is added without one the list is what makes the omission
| invisible. So `accessTenantRouteDeclarations()` walks `Route::getRoutes()`,
| keeps every route that accepts a tenant token, and reads the permission
| key back out of the middleware parameter. Its three mandatory mutation
| controls are scratch routes registered at run time - one with no
| declaration, one naming a key the registry does not hold, and one under a
| prefix no part of this file mentions - and each must turn the sweep red.
| The assertion is extracted so it can be RUN TWICE: green on the shipped
| router, then expected to throw with each scratch route in place. Nothing
| in `src/` is mutated to produce them.
|
| **The pair is what makes a refusal attributable to its own key** (AC-64).
| Every guarded route is called twice: once by an employee holding every
| registered key EXCEPT the route's own, and once by an employee holding
| only that key. A test that asserted "403" alone would be satisfied by a
| sibling guard, an expired token, a non-acting employee or a tenant that is
| not servable - four different defects that all look like a refusal.
|
| **The counts are exact.** Exactly one `access_log` row per denial, exactly
| zero for a request that passes the guard: "at least one" is satisfied by a
| retry and by a second writer, and an operator reading the log cannot tell
| either from two real denials.
|
| **The tenant databases are seeded through `AccessTenantFixture::enter()`**
| (Ruling 7), which is the only connection in this suite with foreign keys
| actually enforced. The requests themselves go through the real middleware,
| which enters the tenant the way production does; nothing here asserts a
| foreign key over that connection.
|
| No engine: the master is the in-memory SQLite stand-in and the tenant is a
| file under storage/app. Nothing reaches 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const ACCESS_HTTP_CLOCK = '2026-10-20 10:00:00.000';

const ACCESS_HTTP_KID = 'access-http-key';

beforeEach(function () {
    authFixtureClockAt(ACCESS_HTTP_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(ACCESS_HTTP_KID);

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Does this route accept a tenant token?
 *
 * The question AC-63 is about, and it is asked of the middleware the route
 * actually carries rather than of its URI: a route is tenant-scoped exactly
 * when `RequireTenantToken` verifies it, because that is the class that
 * enters a shop.
 */
function accessRouteTakesTenantToken(RoutingRoute $route): bool
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (is_string($middleware) && $middleware === RequireTenantToken::class) {
            return true;
        }
    }

    return false;
}

/**
 * Every tenant route, with the declarations it carries.
 *
 * @return list<array{route: string, declarations: list<string>}>
 */
function accessTenantRouteDeclarations(): array
{
    $rows = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! accessRouteTakesTenantToken($route)) {
            continue;
        }

        $declarations = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, RequireTenantPermission::class.':')) {
                continue;
            }

            $declarations[] = substr($middleware, strlen(RequireTenantPermission::class) + 1);
        }

        $rows[] = [
            'route' => implode('|', $route->methods()).' '.$route->uri(),
            'declarations' => $declarations,
        ];
    }

    return $rows;
}

/**
 * AC-63 itself, as a callable, so it can be run against a mutated router.
 */
function assertEveryTenantRouteIsDeclared(): void
{
    $rows = accessTenantRouteDeclarations();
    $registered = array_map(
        static fn ($declaration): string => $declaration->key,
        app(PermissionRegistry::class)->declarations(),
    );

    $printed = [];
    $authenticatedOnly = [];

    foreach ($rows as $row) {
        $printed[] = $row['route'].' -> '.(implode(' + ', $row['declarations']) ?: 'NONE');

        if ($row['declarations'] === [RequireTenantPermission::AUTHENTICATED_ONLY]) {
            $authenticatedOnly[] = $row['route'];
        }
    }

    $domain = 'tenant routes: '.implode(' / ', $printed)
        .' | authenticated_only: '.(implode(' / ', $authenticatedOnly) ?: 'none');

    // Published and non-empty: "every route is declared" is also what a
    // sweep over no routes at all reports.
    expect($rows)->not->toBe([], $domain)
        ->and($registered)->not->toBe([]);

    foreach ($rows as $row) {
        expect($row['declarations'])->toHaveCount(1, "[{$row['route']}] must declare exactly one permission key. {$domain}");

        $declaration = $row['declarations'][0];

        if ($declaration === RequireTenantPermission::AUTHENTICATED_ONLY) {
            continue;
        }

        // `in_array` rather than `toContain`, which is variadic: a second
        // argument there is a second needle, not a message, and the
        // explanation would be silently asserted as a value.
        expect(in_array($declaration, $registered, true))
            ->toBeTrue("[{$row['route']}] declares [{$declaration}], which no provider registers. {$domain}");
    }
}

/**
 * A master user, a shop, the mapping that points it at the fixture tenant,
 * and the registry synced into that tenant.
 *
 * @return array<string, string>
 */
function accessHttpWorld(): array
{
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, $userId);

    // Both keys point at the same record: the token names the master's
    // ULID, and the test reads the same database back through the fixture's
    // own nickname.
    $mapping = tenancyFixtureMapping();
    $record = $mapping['tenant-a'];
    $record['schema_version'] = AccessTenantFixture::topVersion();

    config()->set('tenancy.databases', [$tenantId => $record, 'tenant-a' => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    accessInTenant(function (): void {
        /** @var SyncPermissionRegistryHandler $sync */
        $sync = app(SyncPermissionRegistryHandler::class);
        $sync->handle(new SyncPermissionRegistry);
    });

    return ['user' => $userId, 'tenant' => $tenantId];
}

/**
 * Run something inside the fixture tenant, then leave.
 *
 * Entering through {@see AccessTenantFixture::enter()} rather than through
 * `tenancy()->enter()` is Ruling 7: that is the only path on which the
 * tenant tree's foreign keys are enforced, so a seed this suite would
 * refuse in production is refused here too.
 */
function accessInTenant(callable $work): mixed
{
    AccessTenantFixture::switchTo('tenant-a');

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

/**
 * A tenant access token for this employee.
 */
function accessHttpToken(string $userId, string $tenantId, ?string $employeeId): string
{
    /** @var IssueTokenPairHandler $handler */
    $handler = app(IssueTokenPairHandler::class);

    return $handler->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: $userId,
        tenantId: $tenantId,
        employeeId: $employeeId,
    ))->accessToken;
}

/**
 * The six keys the registry holds, in a stable order.
 *
 * @return list<string>
 */
function accessRegisteredKeys(): array
{
    $keys = array_map(
        static fn ($declaration): string => $declaration->key,
        app(PermissionRegistry::class)->declarations(),
    );

    sort($keys);

    return $keys;
}

/**
 * Every `access_log` row in the fixture tenant.
 *
 * @return list<array<string, mixed>>
 */
function accessLogRowsOverHttp(): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = accessInTenant(static fn (): array => AccessSeed::connection()->table('access_log')
        ->orderBy('id')
        ->get()
        ->map(static fn ($row): array => (array) $row)
        ->all());

    return $rows;
}

it('declares exactly one registered permission key on every tenant route, and the sweep prints them (AC-63)', function () {
    assertEveryTenantRouteIsDeclared();
});

it('reports a tenant route with no declaration (AC-63, mutation control a)', function () {
    Route::middleware(['api', RequireTenantToken::class])
        ->post('/t10/undeclared', fn () => response()->json([]));

    expect(fn () => assertEveryTenantRouteIsDeclared())->toThrow(ExpectationFailedException::class);
});

it('reports a tenant route naming a key no provider registers (AC-63, mutation control b)', function () {
    Route::middleware(['api', RequireTenantToken::class, RequireTenantPermission::class.':employees.manege'])
        ->post('/t10/typo', fn () => response()->json([]));

    expect(fn () => assertEveryTenantRouteIsDeclared())->toThrow(ExpectationFailedException::class);
});

it('reports a tenant route registered by a module this test never mentions (AC-63, mutation control c)', function () {
    // Registered the way another module's provider would, under a prefix
    // nothing in this file names. The sweep finds it because it reads the
    // router, which is the property being measured.
    Route::middleware(['api', RequireTenantToken::class])
        ->prefix('api/v1/stockroom')
        ->group(function (): void {
            Route::get('/shelves', fn () => response()->json([]));
        });

    expect(fn () => assertEveryTenantRouteIsDeclared())->toThrow(ExpectationFailedException::class);
});

it('calls the handler once when the key is held and never when it is not, counted on a spy (AC-64)', function () {
    $world = accessHttpWorld();

    $calls = 0;

    Route::middleware(['api', RequireTenantToken::class, RequireTenantPermission::class.':roles.view'])
        ->post('/t10/probe', function () use (&$calls) {
            $calls++;

            return response()->json(['ok' => true]);
        });

    [$withoutKey, $withKey] = accessInTenant(static function (): array {
        $keys = accessRegisteredKeys();
        $others = array_values(array_filter($keys, static fn (string $key): bool => $key !== 'roles.view'));

        return [
            AccessSeed::actorHolding('all-but-k', $others),
            AccessSeed::actorHolding('only-k', ['roles.view']),
        ];
    });

    json('POST', '/t10/probe', [], [
        'Authorization' => 'Bearer '.accessHttpToken($world['user'], $world['tenant'], $withoutKey),
    ])->assertForbidden()->assertJson(['error' => 'not_permitted']);

    expect($calls)->toBe(0);

    json('POST', '/t10/probe', [], [
        'Authorization' => 'Bearer '.accessHttpToken($world['user'], $world['tenant'], $withKey),
    ])->assertOk();

    // Exactly one, and it is the same spy that counted zero above - which
    // is what makes the zero mean "the probe was watching".
    expect($calls)->toBe(1);
});

it('refuses every guarded route for an employee holding every other key, and logs exactly one row each (AC-64, AC-69)', function () {
    $world = accessHttpWorld();

    $ids = accessInTenant(static function (): array {
        $branch = AccessSeed::branch();
        $employee = AccessSeed::employee('subject');

        AccessSeed::attach($employee, $branch);

        return [
            'employee' => $employee,
            'role' => AccessSeed::role('shift_lead'),
            'branch' => $branch,
            'key' => AccessSeed::permission('fixture.scratch'),
        ];
    });

    $examined = [];

    /** @var array<string, string> $actors */
    $actors = [];

    foreach (accessTenantRouteDeclarations() as $row) {
        [$method, $uri] = explode(' ', $row['route'], 2);

        $method = explode('|', $method)[0];
        $declaration = $row['declarations'][0];

        if ($declaration === RequireTenantPermission::AUTHENTICATED_ONLY) {
            continue;
        }

        $path = '/'.str_replace(
            ['{employee}', '{role}', '{permissionKey}', '{branch}'],
            [$ids['employee'], $ids['role'], $ids['key'], $ids['branch']],
            $uri
        );

        // One actor per DECLARATION, not per route: four routes declare
        // `roles.manage`, and a second employee with the same code would
        // be refused by the unique key the schema is right to have.
        if (! isset($actors[$declaration])) {
            $actors[$declaration] = accessInTenant(static function () use ($declaration): string {
                $others = array_values(array_filter(
                    accessRegisteredKeys(),
                    static fn (string $key): bool => $key !== $declaration
                ));

                return AccessSeed::actorHolding('not-'.substr(md5($declaration), 0, 8), $others);
            });
        }

        $actor = $actors[$declaration];

        $before = count(accessLogRowsOverHttp());

        json($method, $path, [], [
            'Authorization' => 'Bearer '.accessHttpToken($world['user'], $world['tenant'], $actor),
        ])->assertForbidden()->assertJson(['error' => 'not_permitted']);

        $rows = accessLogRowsOverHttp();

        expect(count($rows) - $before)->toBe(1, "[{$row['route']}] must write exactly one access_log row");

        $written = $rows[count($rows) - 1];

        expect((string) $written['type'])->toBe(AccessLogWriter::TYPE_PERMISSION_DENIED)
            ->and((string) $written['employee_id'])->toBe($actor)
            ->and((string) $written['permission_key'])->toBe($declaration)
            ->and(AccessLogWriter::unpackIp($written['ip'] === null ? null : (string) $written['ip']))->toBe('127.0.0.1')
            ->and(json_decode((string) $written['detail'], true))->toBe(['reason' => 'not_granted']);

        $examined[] = $row['route'].' -> '.$declaration;
    }

    expect($examined)->not->toBe([], 'guarded routes exercised: '.implode(' / ', $examined))
        ->and(count($examined))->toBe(count(accessTenantRouteDeclarations()));
});

it('lets the same routes through for an employee holding only their own key (AC-64, the other half)', function () {
    $world = accessHttpWorld();

    $ids = accessInTenant(static function (): array {
        $branch = AccessSeed::branch();
        $employee = AccessSeed::employee('subject');

        AccessSeed::attach($employee, $branch);

        return [
            'employee' => $employee,
            'role' => AccessSeed::role('shift_lead'),
            'branch' => $branch,
            'key' => AccessSeed::permission('fixture.scratch'),
        ];
    });

    $passed = [];

    /** @var array<string, string> $actors */
    $actors = [];

    foreach (accessTenantRouteDeclarations() as $row) {
        [$method, $uri] = explode(' ', $row['route'], 2);

        $method = explode('|', $method)[0];
        $declaration = $row['declarations'][0];

        if ($declaration === RequireTenantPermission::AUTHENTICATED_ONLY) {
            continue;
        }

        $path = '/'.str_replace(
            ['{employee}', '{role}', '{permissionKey}', '{branch}'],
            [$ids['employee'], $ids['role'], $ids['key'], $ids['branch']],
            $uri
        );

        if (! isset($actors[$declaration])) {
            $actors[$declaration] = accessInTenant(
                static fn (): string => AccessSeed::actorHolding('only-'.substr(md5($declaration), 0, 8), [$declaration])
            );
        }

        $actor = $actors[$declaration];

        $before = count(accessLogRowsOverHttp());

        $response = json($method, $path, [], [
            'Authorization' => 'Bearer '.accessHttpToken($world['user'], $world['tenant'], $actor),
        ]);

        // The guard passed. What the slice then does with an empty body is
        // its own criterion - several of these are refused `validation` or
        // by a sibling guard - so what is asserted here is that the refusal
        // is NOT the guard's: no `permission_denied` row, and no
        // `not_permitted` body.
        expect(count(accessLogRowsOverHttp()))->toBe($before, "[{$row['route']}] wrote an access_log row for a held key")
            ->and($response->json('error'))->not->toBe('not_permitted');

        $passed[] = $row['route'].' -> '.$response->getStatusCode();
    }

    expect($passed)->not->toBe([], 'guarded routes passed: '.implode(' / ', $passed));
});

it('records the explicit deny and the out-of-scope cases under their own reasons (AC-64)', function () {
    $world = accessHttpWorld();

    $ids = accessInTenant(static function (): array {
        $branch = AccessSeed::branch();

        // Holds the key through a role, and is denied it personally: the
        // deny wins at every scope and the log says which of the three
        // causes applied.
        $denied = AccessSeed::actorHolding('denied', ['roles.view']);
        AccessSeed::personal($denied, 'roles.view', false);

        // Holds the key, but only at a branch - and `roles.view` is not
        // branch-scoped, so a branch grant confers nothing (D-8).
        $elsewhere = AccessSeed::employee('elsewhere');
        AccessSeed::attach($elsewhere, $branch);
        AccessSeed::assign($elsewhere, AccessSeed::role('branch_viewer', ['roles.view']), $branch);

        return ['denied' => $denied, 'elsewhere' => $elsewhere];
    });

    foreach ([['denied', 'explicit_deny'], ['elsewhere', 'out_of_scope']] as [$who, $reason]) {
        $before = count(accessLogRowsOverHttp());

        json('GET', '/api/v1/access/roles', [], [
            'Authorization' => 'Bearer '.accessHttpToken($world['user'], $world['tenant'], $ids[$who]),
        ])->assertForbidden()->assertJson(['error' => 'not_permitted']);

        $rows = accessLogRowsOverHttp();

        expect(count($rows) - $before)->toBe(1)
            ->and(json_decode((string) $rows[count($rows) - 1]['detail'], true))->toBe(['reason' => $reason]);
    }
});

it('refuses every guarded route for a token with no employee claim, and writes no log row (AC-66, AC-72)', function () {
    $world = accessHttpWorld();

    $token = accessHttpToken($world['user'], $world['tenant'], null);

    json('GET', '/api/v1/access/roles', [], ['Authorization' => 'Bearer '.$token])
        ->assertForbidden()
        ->assertJson(['error' => 'not_permitted']);

    // No row: `employee_id` would be null and `detail.reason` describes the
    // state of somebody's grants, which were never read. An unattributable
    // row giving a reason about nothing is a worse record than none, and
    // AC-69 keeps authentication-shaped refusals out of this table.
    expect(accessLogRowsOverHttp())->toBe([]);
});

it('returns no PIN digest, counter, lock or version in any employee response (AC-65)', function () {
    $world = accessHttpWorld();

    $employeeId = accessInTenant(static function (): string {
        $id = AccessSeed::employee('cashier');

        AccessSeed::connection()->table('employees')->where('id', $id)->update([
            'pin_hash' => 'a-stored-digest',
            'pin_failed_attempts' => 3,
            'pin_locked_until' => '2026-10-20 11:00:00.000',
        ]);

        return $id;
    });

    $actor = accessInTenant(static fn (): string => AccessSeed::actorHolding('viewer', ['employees.view']));
    $headers = ['Authorization' => 'Bearer '.accessHttpToken($world['user'], $world['tenant'], $actor)];

    foreach (['/api/v1/access/employees', '/api/v1/access/employees/'.$employeeId] as $path) {
        $response = json('GET', $path, [], $headers);

        $response->assertOk();

        $body = (string) $response->getContent();

        expect($body)->not->toContain('a-stored-digest')
            ->and($body)->not->toContain('pin_hash')
            ->and($body)->not->toContain('pin_failed_attempts')
            ->and($body)->not->toContain('pin_locked_until')
            ->and($body)->not->toContain('perm_version')
            // The liveness half: the response really is the employee, so
            // the five absences above are absences from something.
            ->and($body)->toContain('"has_pin":true')
            ->and($body)->toContain('cashier');
    }
});

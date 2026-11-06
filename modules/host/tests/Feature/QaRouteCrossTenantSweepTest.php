<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequirePlatformToken;
use App\Http\Middleware\RequireTenantToken;
use App\Http\Middleware\RequireTerminalToken;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Registry\AccessPermissions;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Platform\PlanLimits;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-022 Category A - the route cross-tenant sweep (AC-1 to AC-7, AC-24)
|--------------------------------------------------------------------------
|
| Permanent, CI-gate-enforced (docs/specs/T-022, AC-25). Every route this
| sweep attacks is enumerated at run time from Route::getRoutes(), never
| from a hand-maintained list of route names; a new route is classified
| and, if it carries a body or a path parameter this file does not yet
| know, the sweep fails loudly naming it (QA_ROUTE_SWEEP_RECIPES/
| QA_ROUTE_SWEEP_PARAMETER_SEEDERS are fail-closed) rather than silently
| skipping it.
|
| Two real SQLite tenant databases back every attack (AccessTenantFixture).
| QA_ROUTE_SWEEP_FLOOR/QA_ROUTE_SWEEP_EXCLUDED are the 42/8-route regression
| floors measured at plan step 0; a route silently reclassified away from
| either table is a failing count (AC-2, AC-6), not a silently shrinking
| sweep.
*/

require_once __DIR__.'/../Support/organization-http-fixture.php';

/**
 * The 42 tenant-token-gated route names measured at plan step 0 (organization's
 * 21 plus terminal.settings plus access's 20).
 *
 * @var list<string>
 */
const QA_ROUTE_SWEEP_FLOOR = [
    'organization.branches.list', 'organization.branches.create', 'organization.branches.get',
    'organization.branches.update', 'organization.branches.status',
    'organization.locations.list', 'organization.locations.create', 'organization.locations.get',
    'organization.locations.update', 'organization.locations.status',
    'organization.terminals.list', 'organization.terminals.create', 'organization.terminals.get',
    'organization.terminals.devices', 'organization.terminals.update', 'organization.terminals.activation-code',
    'organization.terminals.status', 'organization.terminals.revoke',
    'organization.settings.list', 'organization.settings.set', 'organization.settings.clear',
    'terminal.settings',
    'access.me.permissions', 'access.roles.list', 'access.roles.create', 'access.roles.get',
    'access.roles.rename', 'access.roles.permissions', 'access.roles.delete',
    'access.employees.list', 'access.employees.create', 'access.employees.get', 'access.employees.update',
    'access.employees.status', 'access.employees.delete',
    'access.assignments.create', 'access.assignments.delete',
    'access.permissions.set', 'access.permissions.clear',
    'access.branches.attach', 'access.branches.detach',
    'access.employees.pin',
];

/**
 * name => reason, the 8 routes that carry no tenant-identifying token.
 *
 * @var array<string, string>
 */
const QA_ROUTE_SWEEP_EXCLUDED = [
    'auth.login' => 'credentials in, no token yet',
    'auth.logout' => 'must work with no valid token at all',
    'auth.refresh' => 'a refresh token in the body, not an access token',
    'auth.tenant' => 'a platform token selecting a tenant, not yet inside one',
    'terminal.activate' => 'an activation code is the credential; no terminal token exists yet',
    'terminal.refresh' => 'a refresh token in the body, not an access token',
    'demo.ping' => 'the unauthenticated example route',
    'api.openapi' => 'master-only generated document, no tenant data',
];

/** name => idsKey, {permissionKey} excluded (shared registry key, not a leak token). */
const QA_ROUTE_SWEEP_PARAMETER_SEEDERS = [
    'branch' => 'branch', 'location' => 'location', 'terminal' => 'terminal',
    'role' => 'role', 'employee' => 'employee', 'permissionKey' => 'permissionKey',
];

/** Collection GET routes this seed produces no distinguishing value for. */
const QA_ROUTE_SWEEP_NO_TENANT_DATA = [
    'access.me.permissions' => 'reports the caller`s own permissions, carries no seeded resource',
    'terminal.settings' => 'the seeded settings carry no marker this route echoes back',
];

/**
 * route name => ['body' => Closure(ids): array, 'prepare' => ?Closure(ids): void, 'params' => ?array<string,string>].
 * One entry per non-GET tenant route (28).
 *
 * @return array<string, array<string, mixed>>
 */
function qaRouteSweepRecipes(): array
{
    return [
        'organization.branches.create' => ['body' => fn (array $ids): array => [
            'code' => 'rtb'.substr(md5((string) mt_rand()), 0, 5), 'name' => 'RT Branch', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
        ]],
        'organization.branches.update' => ['body' => fn (array $ids): array => ['name' => 'Updated branch']],
        'organization.branches.status' => ['body' => fn (array $ids): array => ['is_active' => false], 'params' => ['branch' => 'branchSecond']],
        'organization.locations.create' => ['body' => fn (array $ids): array => [
            'code' => 'rtw'.substr(md5((string) mt_rand()), 0, 5), 'name' => 'RT Warehouse',
        ]],
        'organization.locations.update' => ['body' => fn (array $ids): array => ['name' => 'Updated location']],
        'organization.locations.status' => ['body' => fn (array $ids): array => ['is_active' => false], 'params' => ['location' => 'locationSecond']],
        'organization.terminals.create' => ['body' => fn (array $ids): array => [
            'branch_id' => $ids['branch'], 'location_id' => $ids['location'],
            'code' => 'rtt'.substr(md5((string) mt_rand()), 0, 5), 'name' => 'RT Terminal',
        ]],
        'organization.terminals.update' => ['body' => fn (array $ids): array => ['name' => 'Updated terminal']],
        'organization.terminals.activation-code' => ['body' => fn (array $ids): array => [], 'params' => ['terminal' => 'terminalPending']],
        'organization.terminals.status' => ['body' => fn (array $ids): array => ['status' => 'inactive']],
        'organization.terminals.revoke' => ['body' => fn (array $ids): array => []],
        'organization.settings.set' => ['body' => fn (array $ids): array => [
            'key' => 'rt.write.key', 'scope_type' => 'company', 'scope_id' => null, 'value' => 'rt-value',
        ]],
        'organization.settings.clear' => ['body' => fn (array $ids): array => [
            'key' => 'rt.company.key', 'scope_type' => 'company', 'scope_id' => null,
        ]],
        'access.roles.create' => ['body' => fn (array $ids): array => [
            'key' => 'rtrole'.substr(md5((string) mt_rand()), 0, 5), 'name' => 'RT role',
        ]],
        'access.roles.rename' => ['body' => fn (array $ids): array => ['name' => 'Renamed role'], 'params' => ['role' => 'roleUnassigned']],
        'access.roles.permissions' => ['body' => fn (array $ids): array => ['permission_keys' => [$ids['permissionKey']]], 'params' => ['role' => 'roleUnassigned']],
        'access.roles.delete' => ['body' => fn (array $ids): array => [], 'params' => ['role' => 'roleUnassigned']],
        'access.employees.create' => ['body' => fn (array $ids): array => [
            'code' => 'rtemp'.substr(md5((string) mt_rand()), 0, 5), 'first_name' => 'RT',
        ]],
        'access.employees.update' => ['body' => fn (array $ids): array => ['display_name' => 'Updated employee']],
        'access.employees.status' => ['body' => fn (array $ids): array => ['status' => 'suspended']],
        'access.employees.delete' => ['body' => fn (array $ids): array => []],
        'access.assignments.create' => ['body' => fn (array $ids): array => ['role_id' => $ids['roleUnassigned']]],
        'access.assignments.delete' => ['body' => fn (array $ids): array => []],
        'access.permissions.set' => ['body' => fn (array $ids): array => ['allowed' => true]],
        'access.permissions.clear' => ['body' => fn (array $ids): array => []],
        'access.branches.attach' => ['body' => fn (array $ids): array => ['branch_id' => $ids['branchSecond']]],
        'access.branches.detach' => ['body' => fn (array $ids): array => []],
        'access.employees.pin' => ['body' => fn (array $ids): array => ['pin' => '194637']],
    ];
}

/**
 * The 6 by-id GET routes this sweep attacks (AC-3).
 *
 * @var list<string>
 */
const QA_ROUTE_SWEEP_BY_ID_READS = [
    'organization.branches.get', 'organization.locations.get', 'organization.terminals.get',
    'organization.terminals.devices', 'access.roles.get', 'access.employees.get',
];

/**
 * The 8 collection GET routes this sweep attacks (AC-4): no path parameter,
 * tenant or terminal gated.
 *
 * @var list<string>
 */
const QA_ROUTE_SWEEP_COLLECTIONS = [
    'organization.branches.list', 'organization.locations.list', 'organization.terminals.list',
    'organization.settings.list', 'access.me.permissions', 'access.roles.list', 'access.employees.list',
    'terminal.settings',
];

/**
 * Every live route whose URI starts with `api/v1/`, classified by its own
 * registered middleware - never a hand-maintained list of names (AC-1).
 *
 * @return list<array{name: string, methods: list<string>, uri: string, class: string}>
 */
function qaRouteSweepRoutes(): array
{
    app(Kernel::class);

    $rows = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/') && $route->uri() !== 'api/v1/openapi.json') {
            continue;
        }

        $rows[] = [
            'name' => (string) $route->getName(),
            'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
            'uri' => $route->uri(),
            'class' => qaRouteSweepClassify($route),
        ];
    }

    return $rows;
}

function qaRouteSweepClassify(RoutingRoute $route): string
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }

        if ($middleware === RequireTenantToken::class) {
            return 'tenant';
        }

        if ($middleware === RequireTerminalToken::class) {
            return 'terminal';
        }

        if ($middleware === RequirePlatformToken::class) {
            return 'platform';
        }
    }

    return 'none';
}

/**
 * AC-2: the floor, found and classified tenant or terminal.
 */
function qaRouteSweepAssertFloor(): void
{
    $rows = qaRouteSweepRoutes();
    $byName = [];

    foreach ($rows as $row) {
        $byName[$row['name']] = $row;
    }

    $gated = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['class'], ['tenant', 'terminal'], true)));

    expect(count($gated))->toBeGreaterThanOrEqual(count(QA_ROUTE_SWEEP_FLOOR));

    foreach (QA_ROUTE_SWEEP_FLOOR as $name) {
        expect(isset($byName[$name]))->toBeTrue("Expected route [{$name}] to exist.");
        expect(in_array($byName[$name]['class'], ['tenant', 'terminal'], true))
            ->toBeTrue("Route [{$name}] is classified [{$byName[$name]['class']}], not tenant/terminal-gated.");
    }
}

/**
 * AC-6: every `platform`/`none` route is in the excluded table, with a
 * reason, and every table entry names a live route - fail-closed both ways.
 */
function qaRouteSweepAssertExcluded(): void
{
    $rows = qaRouteSweepRoutes();
    $byName = [];

    foreach ($rows as $row) {
        $byName[$row['name']] = $row;
    }

    foreach ($rows as $row) {
        if (in_array($row['class'], ['platform', 'none'], true)) {
            expect(array_key_exists($row['name'], QA_ROUTE_SWEEP_EXCLUDED))
                ->toBeTrue("Route [{$row['name']}] is {$row['class']} and not in QA_ROUTE_SWEEP_EXCLUDED.");
        }
    }

    foreach (QA_ROUTE_SWEEP_EXCLUDED as $name => $reason) {
        expect(isset($byName[$name]))->toBeTrue("QA_ROUTE_SWEEP_EXCLUDED names [{$name}], which does not exist.");
        expect($reason)->not->toBe('');
    }

    fwrite(STDOUT, PHP_EOL.'AC-6 excluded routes: '.implode('; ', array_map(
        static fn (string $n, string $r): string => "{$n} ({$r})", array_keys(QA_ROUTE_SWEEP_EXCLUDED), QA_ROUTE_SWEEP_EXCLUDED
    )).PHP_EOL);
}

/*
|--------------------------------------------------------------------------
| The two-tenant world
|--------------------------------------------------------------------------
*/

/**
 * Boot the master and the signing key once per test, then re-copy both
 * tenant databases from their templates on every call - a write route's
 * reseed (plan step 9) must not re-migrate the master or re-install the
 * same key twice.
 */
function qaRouteSweepWorld(): void
{
    if (! ($GLOBALS['qaRouteSweepMasterBooted'] ?? false)) {
        authFixtureClockAt(ORG_HTTP_CLOCK);
        authFixtureMigrateMaster();
        authFixtureInstallKey(ORG_HTTP_KID);

        $GLOBALS['qaRouteSweepMasterBooted'] = true;
    }

    AccessTenantFixture::boot();
}

/**
 * Seed one fixture tenant: an owner (with an access token), an employee
 * carrying the marker, two roles, a branch/location/terminal, settings at
 * three scopes, a personal permission override and a branch attachment.
 *
 * @return array{ids: array<string, string>, tokens: list<string>, ownerToken: string, terminalToken: string}
 */
function qaRouteSweepSeed(string $fixtureTenant, string $marker): array
{
    AccessTenantFixture::switchTo($fixtureTenant);

    try {
        /** @var SyncPermissionRegistryHandler $sync */
        $sync = app(SyncPermissionRegistryHandler::class);
        $sync->handle(new SyncPermissionRegistry);

        $owner = AccessSeed::employee('owner-'.$fixtureTenant);
        $ownerRole = (string) AccessSeed::connection()->table('roles')->where('key', 'owner')->value('id');
        AccessSeed::assign($owner, $ownerRole);

        $marked = AccessSeed::employee('subject-'.$fixtureTenant, ['display_name' => $marker]);

        $role = AccessSeed::role($marker.'-role', [AccessPermissions::ROLES_VIEW]);
        $roleUnassigned = AccessSeed::role($marker.'-role-unassigned', [AccessPermissions::ROLES_VIEW]);
        AccessSeed::assign($marked, $role);

        $branch = AccessSeed::branch();
        AccessSeed::connection()->table('branches')->where('id', $branch)->update(['name' => $marker]);

        AccessSeed::connection()->table('locations')->insert([
            'id' => $location = (string) Str::ulid(),
            'branch_id' => $branch, 'code' => 'LOC'.substr(md5($marker), 0, 4), 'name' => $marker,
            'type' => 'branch_store', 'is_active' => true, 'is_sellable_from' => true, 'sort_order' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        AccessSeed::connection()->table('terminals')->insert([
            'id' => $terminalId = (string) Str::ulid(),
            'branch_id' => $branch, 'location_id' => $location, 'code' => 'TRM'.substr(md5($marker), 0, 4),
            'name' => $marker, 'status' => 'pending',
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        AccessSeed::personal($marked, AccessPermissions::ROLES_VIEW, false, null);
        AccessSeed::attach($marked, $branch);

        // A second branch, never attached to $marked - access.branches.attach's
        // own recipe (plan N-9) needs an id-bearing write whose own-tenant
        // control is not already a duplicate of the seeded attachment above.
        $secondBranch = AccessSeed::branch();

        // A second, unused location - organization.locations.status's own
        // write deactivates a location, which the one above is refused for
        // (it is a terminal's selling location).
        AccessSeed::connection()->table('locations')->insert([
            'id' => $secondLocation = (string) Str::ulid(),
            'code' => 'LOC2'.substr(md5($marker), 0, 3), 'name' => $marker.'-2',
            'type' => 'warehouse', 'is_active' => true, 'sort_order' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        AccessSeed::connection()->table('settings_registry')->insert([
            'key' => 'rt.company.key', 'module' => 'fixture', 'type' => 'string',
            'default_value' => null, 'validation' => null, 'sync_to_pos' => false, 'is_secret' => false, 'plugin_name' => null,
        ]);
        AccessSeed::connection()->table('settings_registry')->insert([
            'key' => 'rt.write.key', 'module' => 'fixture', 'type' => 'string',
            'default_value' => null, 'validation' => null, 'sync_to_pos' => false, 'is_secret' => false, 'plugin_name' => null,
        ]);
        AccessSeed::connection()->table('settings')->insert([
            'id' => (string) Str::ulid(), 'scope_type' => 'company', 'scope_id' => null,
            'key' => 'rt.company.key', 'value' => json_encode($marker), 'updated_by' => null,
            'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        $claims = AccessSeed::tokenClaims($owner);
        $terminalClaims = AccessSeed::tokenClaims($marked);
    } finally {
        tenancy()->leave();
    }

    $tenantId = qaRouteSweepMasterTenantFor($fixtureTenant);
    $userId = qaRouteSweepMasterUserFor($fixtureTenant);

    /** @var IssueTokenPairHandler $tokens */
    $tokens = app(IssueTokenPairHandler::class);

    $ownerToken = $tokens->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT, userId: $userId, tenantId: $tenantId, employeeClaims: $claims,
    ))->accessToken;

    $terminalOwnerToken = $ownerToken;
    $terminalActivated = orgHttpActivated($terminalOwnerToken, ['branch' => $branch, 'store' => $location], 'rt'.substr(md5($marker), 0, 6));

    $ids = [
        'employee' => $marked, 'role' => $role, 'roleUnassigned' => $roleUnassigned,
        'branch' => $branch, 'branchSecond' => $secondBranch, 'location' => $location, 'locationSecond' => $secondLocation,
        'terminal' => $terminalActivated['terminal'], 'terminalPending' => $terminalId,
        'permissionKey' => AccessPermissions::ROLES_VIEW,
    ];

    return [
        'ids' => $ids,
        'tokens' => array_values(array_filter([$marked, $role, $roleUnassigned, $branch, $secondBranch, $location, $secondLocation, $terminalActivated['terminal'], $terminalId, $marker])),
        'ownerToken' => $ownerToken,
        'terminalToken' => $terminalActivated['access'],
    ];
}

$GLOBALS['qaRouteSweepMasterTenants'] = [];

$GLOBALS['qaRouteSweepMasterUsers'] = [];

function qaRouteSweepMasterTenantFor(string $fixtureTenant): string
{
    if (! isset($GLOBALS['qaRouteSweepMasterTenants'][$fixtureTenant])) {
        $GLOBALS['qaRouteSweepMasterTenants'][$fixtureTenant] = authFixtureTenant(strtoupper('RTSHOP-'.$fixtureTenant.'-'.bin2hex(random_bytes(3))));
    }

    return $GLOBALS['qaRouteSweepMasterTenants'][$fixtureTenant];
}

function qaRouteSweepMasterUserFor(string $fixtureTenant): string
{
    if (! isset($GLOBALS['qaRouteSweepMasterUsers'][$fixtureTenant])) {
        $userId = authFixtureUser('owner-'.$fixtureTenant.'-'.bin2hex(random_bytes(3)).'@example.com');
        authFixtureMembership(qaRouteSweepMasterTenantFor($fixtureTenant), $userId);
        $GLOBALS['qaRouteSweepMasterUsers'][$fixtureTenant] = $userId;
    }

    return $GLOBALS['qaRouteSweepMasterUsers'][$fixtureTenant];
}

/**
 * Map the master tenant ids of both fixture tenants onto their physical
 * databases, and reset every cached instance.
 */
function qaRouteSweepMapBoth(): void
{
    $mapping = tenancyFixtureMapping();

    $configured = (array) config('tenancy.databases', []);

    foreach (['tenant-a', 'tenant-b'] as $fixture) {
        $record = $mapping[$fixture];
        $record['schema_version'] = AccessTenantFixture::topVersion();
        $configured[qaRouteSweepMasterTenantFor($fixture)] = $record;
        $configured[$fixture] = $record;
    }

    config()->set('tenancy.databases', $configured);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);

    // PlanLimits (T-016) is bound as a plain singleton holding a
    // constructor-injected TenantContext; across several reseeds in one
    // process that captured reference goes stale the moment the tenant it
    // was resolved under leaves, so it has to be forgotten every time this
    // file remaps the tenants too - the same shape as the two lines above.
    app()->forgetInstance(PlanLimits::class);

    app()->forgetScopedInstances();

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $route->flushController();
    }
}

/**
 * Reset both tenant databases, re-run the HTTP world, seed both tenants and
 * return both worlds, keyed `a`/`b`.
 *
 * @return array{a: array<string, mixed>, b: array<string, mixed>}
 */
function qaRouteSweepTwoTenantWorld(): array
{
    qaRouteSweepWorld();
    qaRouteSweepMapBoth();

    $a = qaRouteSweepSeed('tenant-a', 'RTMARK-A-'.bin2hex(random_bytes(3)));
    $b = qaRouteSweepSeed('tenant-b', 'RTMARK-B-'.bin2hex(random_bytes(3)));

    qaRouteSweepMapBoth();

    return ['a' => $a, 'b' => $b];
}

/**
 * Build a request path, substituting every `{param}` with the id this
 * recipe (or the default seeder table) names.
 *
 * @param  array<string, string>  $ids
 * @param  array<string, string>  $overrides
 */
function qaRouteSweepPath(string $uri, array $ids, array $overrides = []): string
{
    $path = $uri;

    foreach (QA_ROUTE_SWEEP_PARAMETER_SEEDERS as $param => $defaultKey) {
        if (! str_contains($path, '{'.$param.'}')) {
            continue;
        }

        $idsKey = $overrides[$param] ?? $defaultKey;

        expect(array_key_exists($idsKey, $ids))->toBeTrue("No seeder for path parameter [{$param}].");

        $path = str_replace('{'.$param.'}', $ids[$idsKey], $path);
    }

    if (preg_match('/\{(\w+)\}/', $path, $match) === 1) {
        throw new \RuntimeException("Route [{$uri}] has an unseeded path parameter [{$match[1]}].");
    }

    return '/'.$path;
}

/**
 * Every victim token found in the response, except ids the request itself
 * carried (an error message may echo the requested id back).
 *
 * @param  TestResponse<Response>  $response
 * @param  list<string>  $victimTokens
 * @param  list<string>  $carried
 * @return list<string>
 */
function qaRouteSweepLeaks(TestResponse $response, array $victimTokens, array $carried): array
{
    $haystack = (string) $response->getContent();
    $haystack .= json_encode($response->headers->all());

    $leaks = [];

    foreach ($victimTokens as $token) {
        if ($token === '' || in_array($token, $carried, true)) {
            continue;
        }

        if (str_contains($haystack, $token)) {
            $leaks[] = $token;
        }
    }

    return $leaks;
}

beforeEach(function () {
    // Nothing: each it() boots its own world, so a filtered single-file
    // run of one case still works (FP-164).
});

afterEach(function () {
    AccessTenantFixture::forget();

    $GLOBALS['qaRouteSweepMasterBooted'] = false;
    $GLOBALS['qaRouteSweepMasterTenants'] = [];
    $GLOBALS['qaRouteSweepMasterUsers'] = [];
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('AC-24: the live route list is non-empty', function () {
    expect(qaRouteSweepRoutes())->not->toBe([]);
});

it('AC-1, AC-2: every floor route is found, classified tenant or terminal', function () {
    qaRouteSweepAssertFloor();
});

it('AC-6: every master-only/unauthenticated route is named, with a reason, fail-closed both ways', function () {
    qaRouteSweepAssertExcluded();
});

it('AC-3: a by-id GET route refuses the other tenant`s id, not-found or validation, and leaks nothing', function () {
    $world = qaRouteSweepTwoTenantWorld();
    $routes = qaRouteSweepRoutes();
    $byName = [];
    foreach ($routes as $row) {
        $byName[$row['name']] = $row;
    }

    $report = [];

    foreach (QA_ROUTE_SWEEP_BY_ID_READS as $name) {
        expect(isset($byName[$name]))->toBeTrue("Route [{$name}] no longer exists.");

        $uri = $byName[$name]['uri'];
        $method = $byName[$name]['methods'][0];

        // Own control.
        $ownPath = qaRouteSweepPath($uri, $world['a']['ids']);
        $ownResponse = json($method, $ownPath, [], ['Authorization' => 'Bearer '.$world['a']['ownerToken']]);

        expect($ownResponse->getStatusCode())
            ->toBeLessThan(300, "[{$name}] own-tenant control must succeed, got {$ownResponse->getStatusCode()}");

        if ($name !== 'organization.terminals.devices') {
            // A pending terminal (never activated) has registered no
            // device yet, so this one by-id read carries no distinguishing
            // value of its own by construction - it is still attacked for
            // refusal below, exactly like every other by-id route.
            $ownHasToken = false;
            foreach ($world['a']['tokens'] as $token) {
                if ($token !== '' && str_contains((string) $ownResponse->getContent(), $token)) {
                    $ownHasToken = true;

                    break;
                }
            }
            expect($ownHasToken)->toBeTrue("[{$name}] own control must contain at least one of A's tokens");
        }

        // Cross: B's token, A's ids.
        $crossResponse = json($method, $ownPath, [], ['Authorization' => 'Bearer '.$world['b']['ownerToken']]);

        expect(in_array($crossResponse->getStatusCode(), [404, 422], true))
            ->toBeTrue("[{$name}] cross attempt must be 404/422, got {$crossResponse->getStatusCode()}");
        expect(qaRouteSweepLeaks($crossResponse, $world['a']['tokens'], []))->toBe([], "[{$name}] cross attempt leaked A's data");

        // Reverse: A's token, B's ids.
        $reversePath = qaRouteSweepPath($uri, $world['b']['ids']);
        $reverseResponse = json($method, $reversePath, [], ['Authorization' => 'Bearer '.$world['a']['ownerToken']]);

        expect(in_array($reverseResponse->getStatusCode(), [404, 422], true))
            ->toBeTrue("[{$name}] reverse attempt must be 404/422, got {$reverseResponse->getStatusCode()}");
        expect(qaRouteSweepLeaks($reverseResponse, $world['b']['tokens'], []))->toBe([], "[{$name}] reverse attempt leaked B's data");

        $report[] = $name.': ok';
    }

    fwrite(STDOUT, PHP_EOL.'AC-3 by-id reads ('.count($report).'): '.implode('; ', $report).PHP_EOL);
});

it('AC-4: a collection GET route carries no other tenant`s distinguishing value', function () {
    $world = qaRouteSweepTwoTenantWorld();
    $routes = qaRouteSweepRoutes();
    $byName = [];
    foreach ($routes as $row) {
        $byName[$row['name']] = $row;
    }

    $noTenantData = [];
    $report = [];

    $queries = ['organization.settings.list' => '?scope_type=company'];

    foreach (QA_ROUTE_SWEEP_COLLECTIONS as $name) {
        expect(isset($byName[$name]))->toBeTrue("Route [{$name}] no longer exists.");

        $uri = '/'.$byName[$name]['uri'].($queries[$name] ?? '');
        $method = $byName[$name]['methods'][0];
        $terminalGated = $byName[$name]['class'] === 'terminal';

        $tokenA = $terminalGated ? $world['a']['terminalToken'] : $world['a']['ownerToken'];
        $tokenB = $terminalGated ? $world['b']['terminalToken'] : $world['b']['ownerToken'];

        $responseA = json($method, $uri, [], ['Authorization' => 'Bearer '.$tokenA]);
        $responseB = json($method, $uri, [], ['Authorization' => 'Bearer '.$tokenB]);

        expect($responseA->getStatusCode())->toBeLessThan(300, "[{$name}] own-tenant control must succeed");
        expect($responseB->getStatusCode())->toBeLessThan(300, "[{$name}] own-tenant control must succeed");

        $aHasOwnMarker = false;
        foreach ($world['a']['tokens'] as $token) {
            if ($token !== '' && str_contains((string) $responseA->getContent(), $token)) {
                $aHasOwnMarker = true;

                break;
            }
        }

        if (! $aHasOwnMarker) {
            $noTenantData[] = $name;
        }

        expect(qaRouteSweepLeaks($responseB, $world['a']['tokens'], []))->toBe([], "[{$name}] tenant B's response leaked tenant A's data");
        expect(qaRouteSweepLeaks($responseA, $world['b']['tokens'], []))->toBe([], "[{$name}] tenant A's response leaked tenant B's data");

        $report[] = $name.': ok';
    }

    sort($noTenantData);
    $expected = array_keys(QA_ROUTE_SWEEP_NO_TENANT_DATA);
    sort($expected);

    expect($noTenantData)->toBe($expected);

    fwrite(STDOUT, PHP_EOL.'AC-4 collections ('.count($report).'): '.implode('; ', $report).PHP_EOL);
});

it('AC-5, N-9: every non-GET tenant route has a recipe, fail-closed, and a write never crosses tenants', function () {
    $routes = qaRouteSweepRoutes();
    $recipes = qaRouteSweepRecipes();

    $nonGetTenant = array_values(array_filter($routes, static fn (array $r): bool => $r['class'] === 'tenant' && $r['methods'][0] !== 'GET'));

    foreach ($nonGetTenant as $route) {
        expect(array_key_exists($route['name'], $recipes))->toBeTrue("Route [{$route['name']}] has no QA_ROUTE_SWEEP_RECIPES entry.");
    }

    $report = [];

    foreach ($nonGetTenant as $route) {
        $recipe = $recipes[$route['name']];
        $uri = $route['uri'];
        $method = $route['methods'][0];
        $params = $recipe['params'] ?? [];
        $idBearing = str_contains($uri, '{');

        if ($idBearing) {
            $world = qaRouteSweepTwoTenantWorld();
            $aPath = qaRouteSweepPath($uri, $world['a']['ids'], $params);
            $bPath = qaRouteSweepPath($uri, $world['b']['ids'], $params);

            // Cross.
            $crossBefore = snapshotBothTenantDatabases();
            $cross = json($method, $aPath, $recipe['body']($world['a']['ids']), [
                'Authorization' => 'Bearer '.$world['b']['ownerToken'], 'Idempotency-Key' => (string) Str::ulid(),
            ]);
            expect(in_array($cross->getStatusCode(), [404, 422], true))
                ->toBeTrue("[{$route['name']}] cross write must be 404/422, got {$cross->getStatusCode()}");
            expect(snapshotBothTenantDatabases())->toBe($crossBefore, "[{$route['name']}] cross write changed a database");

            // Reverse.
            $world = qaRouteSweepTwoTenantWorld();
            $reverseBefore = snapshotBothTenantDatabases();
            $reverse = json($method, $bPath, $recipe['body']($world['b']['ids']), [
                'Authorization' => 'Bearer '.$world['a']['ownerToken'], 'Idempotency-Key' => (string) Str::ulid(),
            ]);
            expect(in_array($reverse->getStatusCode(), [404, 422], true))
                ->toBeTrue("[{$route['name']}] reverse write must be 404/422, got {$reverse->getStatusCode()}");
            expect(snapshotBothTenantDatabases())->toBe($reverseBefore, "[{$route['name']}] reverse write changed a database");

            // Own control, last.
            $world = qaRouteSweepTwoTenantWorld();
            $ownPath = qaRouteSweepPath($uri, $world['a']['ids'], $params);
            $own = json($method, $ownPath, $recipe['body']($world['a']['ids']), [
                'Authorization' => 'Bearer '.$world['a']['ownerToken'], 'Idempotency-Key' => (string) Str::ulid(),
            ]);
            expect($own->getStatusCode())->toBeLessThan(300, "[{$route['name']}] own-tenant write must succeed, got {$own->getStatusCode()} / ".$own->getContent());
        } else {
            $world = qaRouteSweepTwoTenantWorld();
            $bBefore = qaRouteSweepTenantState('tenant-b');

            $own = json($method, '/'.$uri, $recipe['body']($world['a']['ids']), [
                'Authorization' => 'Bearer '.$world['a']['ownerToken'], 'Idempotency-Key' => (string) Str::ulid(),
            ]);
            expect($own->getStatusCode())->toBeLessThan(300, "[{$route['name']}] write must succeed, got {$own->getStatusCode()} / ".$own->getContent());

            $bAfter = qaRouteSweepTenantState('tenant-b');
            expect($bAfter)->toBe($bBefore, "[{$route['name']}] a non-id write changed tenant B");
        }

        $report[] = $route['name'].': ok';
    }

    fwrite(STDOUT, PHP_EOL.'AC-5 writes ('.count($report).'): '.implode('; ', $report).PHP_EOL);
});

/**
 * Every row of every table in one fixture tenant, as JSON.
 */
function qaRouteSweepTenantState(string $fixtureTenant): string
{
    AccessTenantFixture::switchTo($fixtureTenant);

    try {
        $state = [];

        foreach (tenantTreeTables() as $table) {
            $state[$table] = DB::connection(AccessTenantFixture::connectionName())->table($table)->get()->all();
        }

        return (string) json_encode($state);
    } finally {
        tenancy()->leave();
    }
}

/**
 * @return array{a: string, b: string}
 */
function snapshotBothTenantDatabases(): array
{
    return ['a' => qaRouteSweepTenantState('tenant-a'), 'b' => qaRouteSweepTenantState('tenant-b')];
}

/*
|--------------------------------------------------------------------------
| AC-7 - the planted leaks, proving the attempt functions can fail
|--------------------------------------------------------------------------
*/

if (! class_exists('QaRouteSweepPlantedReadController', false)) {
    final class QaRouteSweepPlantedReadController
    {
        public static ?string $marker = null;

        public function __invoke(string $branch): JsonResponse
        {
            // Returns the seeded marker with no tenant check at all - the
            // leak this sweep exists to catch (AC-7).
            return new JsonResponse(['leak' => self::$marker]);
        }
    }
}

if (! class_exists('QaRouteSweepPlantedListController', false)) {
    final class QaRouteSweepPlantedListController
    {
        public static ?string $marker = null;

        public function __invoke(): JsonResponse
        {
            return new JsonResponse(['leak' => self::$marker]);
        }
    }
}

if (! class_exists('QaRouteSweepPlantedWriteController', false)) {
    final class QaRouteSweepPlantedWriteController
    {
        public function __invoke(): JsonResponse
        {
            tenancy()->leave();
            tenancy()->enter('tenant-b');

            try {
                DB::connection(AccessTenantFixture::connectionName())->table('marker')->insert(['value' => 'planted-cross-write']);
            } finally {
                tenancy()->leave();
            }

            return new JsonResponse(['ok' => true]);
        }
    }
}

it('AC-7: the planted read, list and write routes are reported as leaks by the same attempt', function () {
    Route::middleware(['api', RequireTenantToken::class])
        ->get('/api/v1/qa-route-sweep/planted/{branch}', QaRouteSweepPlantedReadController::class)
        ->name('qa.route.sweep.planted.read');

    Route::middleware(['api', RequireTenantToken::class])
        ->get('/api/v1/qa-route-sweep/planted-list', QaRouteSweepPlantedListController::class)
        ->name('qa.route.sweep.planted.list');

    Route::middleware(['api', RequireTenantToken::class])
        ->post('/api/v1/qa-route-sweep/planted-write', QaRouteSweepPlantedWriteController::class)
        ->name('qa.route.sweep.planted.write');

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $route->flushController();
    }

    $world = qaRouteSweepTwoTenantWorld();

    QaRouteSweepPlantedReadController::$marker = $world['a']['ids']['branch'];
    QaRouteSweepPlantedListController::$marker = $world['a']['ids']['branch'];

    $read = json('GET', '/api/v1/qa-route-sweep/planted/'.$world['a']['ids']['branch'], [], [
        'Authorization' => 'Bearer '.$world['b']['ownerToken'],
    ]);
    expect($read->getContent())->toContain($world['a']['ids']['branch']);

    $list = json('GET', '/api/v1/qa-route-sweep/planted-list', [], [
        'Authorization' => 'Bearer '.$world['b']['ownerToken'],
    ]);
    expect($list->getContent())->toContain($world['a']['ids']['branch']);

    AccessTenantFixture::switchTo('tenant-b');
    $bMarkersBefore = DB::connection(AccessTenantFixture::connectionName())->table('marker')->count();
    tenancy()->leave();

    json('POST', '/api/v1/qa-route-sweep/planted-write', [], [
        'Authorization' => 'Bearer '.$world['a']['ownerToken'], 'Idempotency-Key' => (string) Str::ulid(),
    ]);

    AccessTenantFixture::switchTo('tenant-b');
    $bMarkersAfter = DB::connection(AccessTenantFixture::connectionName())->table('marker')->count();
    tenancy()->leave();

    expect($bMarkersAfter)->toBeGreaterThan($bMarkersBefore, 'the planted write did not reach tenant B at all');
});

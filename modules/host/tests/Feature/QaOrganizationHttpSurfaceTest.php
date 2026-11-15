<?php

use App\Http\Middleware\RequireTenantToken;
use App\Http\Middleware\RequireTerminalToken;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-014 steps 15 and 16 - the organization HTTP surface, swept
|--------------------------------------------------------------------------
|
| AC-17 (no delete route), AC-18 (each route has exactly one of the eight keys
| and refuses the three wrong kinds of token), AC-40 (no route un-revokes),
| AC-53 (the declarations are complete and used) and AC-60 (no organization
| route reaches the PIN verification).
|
| **The domain is derived from the router, never listed**: every route whose
| URI starts `api/v1/organization` or `api/v1/terminal`. It is printed, and it
| is asserted non-empty, because a sweep over no routes passes every claim.
| Each claim is a function so a control can run it over a planted fault: a
| delete route, a key nothing routes, a controller that reaches the PIN check.
|
| The owner and the wrong tokens are sent to **every** employee route with a
| ghost id in the path and an empty body, so what is measured is the guard in
| front of the handler, not the handler: a route that the guard lets through
| answers something other than the guard's refusal, and that is what "the owner
| passes" means here.
*/

require_once __DIR__.'/../Support/organization-http-fixture.php';

beforeEach(function () {
    orgHttpBoot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Every route of this task, classified.
 *
 * @return list<array{route: RoutingRoute, label: string, class: string, keys: list<string>}>
 */
function orgSurfaceRoutes(): array
{
    $rows = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/organization') && ! str_starts_with($uri, 'api/v1/terminal')) {
            continue;
        }

        $middleware = array_filter($route->gatherMiddleware(), 'is_string');
        $keys = [];

        foreach ($middleware as $m) {
            if (str_starts_with($m, 'App\\Http\\Middleware\\RequireTenantPermission:')) {
                $keys[] = substr($m, strlen('App\\Http\\Middleware\\RequireTenantPermission:'));
            }
        }

        $class = in_array(RequireTenantToken::class, $middleware, true) ? 'employee'
            : (in_array(RequireTerminalToken::class, $middleware, true) ? 'terminal' : 'public');

        $rows[] = ['route' => $route, 'label' => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$uri, 'class' => $class, 'keys' => $keys];
    }

    return $rows;
}

/**
 * @return list<string>
 */
function orgSurfaceDeleteRoutes(): array
{
    $found = [];

    foreach (orgSurfaceRoutes() as $row) {
        if (in_array('DELETE', $row['route']->methods(), true)) {
            $found[] = $row['label'];
        }
    }

    return $found;
}

/**
 * @return list<string>
 */
function orgSurfaceEightKeys(): array
{
    $keys = array_map(static fn ($d): string => $d->key, OrganizationPermissions::declarations());
    sort($keys);

    return $keys;
}

/**
 * The key report: routes with not exactly one key, routes naming a key that is
 * not declared, and declared keys no route uses.
 *
 * @param  list<string>  $declared
 * @return array{wrongCount: list<string>, undeclared: list<string>, unrouted: list<string>, routed: list<string>}
 */
function orgSurfaceKeyReport(array $declared): array
{
    $wrongCount = [];
    $undeclared = [];
    $routed = [];

    foreach (orgSurfaceRoutes() as $row) {
        if ($row['class'] !== 'employee') {
            if ($row['keys'] !== []) {
                $wrongCount[] = $row['label'].' (not an employee route but carries a key)';
            }

            continue;
        }

        if (count($row['keys']) !== 1) {
            $wrongCount[] = $row['label'].' carries '.count($row['keys']);

            continue;
        }

        $routed[$row['keys'][0]] = $row['keys'][0];

        if (! in_array($row['keys'][0], $declared, true)) {
            $undeclared[] = $row['label'].' -> '.$row['keys'][0];
        }
    }

    $unrouted = array_values(array_diff($declared, array_values($routed)));
    sort($unrouted);

    return ['wrongCount' => $wrongCount, 'undeclared' => $undeclared, 'unrouted' => $unrouted, 'routed' => array_values($routed)];
}

/**
 * The URL of a route with a ghost id in every placeholder, and its method.
 *
 * @param  array{route: RoutingRoute}  $row
 * @return array{string, string}
 */
function orgSurfaceTarget(array $row): array
{
    $methods = array_values(array_diff($row['route']->methods(), ['HEAD']));

    return [$methods[0], '/'.preg_replace('/\{[^}]+\}/', (string) Str::ulid(), $row['route']->uri())];
}

/**
 * @param  list<array{route: RoutingRoute, label: string, class: string, keys: list<string>}>  $rows
 * @return array<string, string> label => status/error
 */
function orgSurfaceStatuses(array $rows, string $token): array
{
    $statuses = [];

    foreach ($rows as $row) {
        [$method, $url] = orgSurfaceTarget($row);
        $response = orgHttpCall($method, $url, $token);

        $statuses[$row['label']] = $response->getStatusCode().'/'.(string) $response->json('error');
    }

    return $statuses;
}

it('has no delete route anywhere in the organization or terminal surface, and the scan reports one it is shown (AC-17)', function () {
    $rows = orgSurfaceRoutes();
    $printed = array_map(static fn (array $r): string => $r['class'].': '.$r['label'].' -> '.(implode('+', $r['keys']) ?: 'none'), $rows);

    fwrite(STDOUT, PHP_EOL.'AC-17/18 surface ('.count($rows).' routes):'.PHP_EOL.implode(PHP_EOL, $printed).PHP_EOL);

    expect(count($rows))->toBeGreaterThan(20);
    expect(orgSurfaceDeleteRoutes())->toBe([]);

    // Control: the same scan, with a delete route mounted.
    Route::middleware('api')->delete('api/v1/organization/branches/{branch}', fn () => 'x');

    expect(orgSurfaceDeleteRoutes())->toBe(['DELETE api/v1/organization/branches/{branch}']);
});

it('gives every employee route exactly one of the eight keys, routes all eight, and reports a stray or an orphan (AC-18, AC-53)', function () {
    $eight = orgSurfaceEightKeys();
    $report = orgSurfaceKeyReport($eight);

    expect($eight)->toHaveCount(8);
    expect($report['wrongCount'])->toBe([]);
    expect($report['undeclared'])->toBe([]);
    // Declared and routed: nothing declared is unused.
    expect($report['unrouted'])->toBe([]);

    $routed = $report['routed'];
    sort($routed);

    expect($routed)->toBe($eight);

    // Controls: a declared key no route uses is reported, and so is a route
    // carrying a key nobody declared or two keys at once.
    expect(orgSurfaceKeyReport(array_merge($eight, ['orphans.view']))['unrouted'])->toBe(['orphans.view']);
    expect(orgSurfaceKeyReport(array_values(array_diff($eight, ['settings.manage'])))['undeclared'])->not->toBe([]);

    Route::middleware(['api', RequireTenantToken::class])->get('api/v1/organization/ghost', fn () => 'x')->name('ghost');

    expect(orgSurfaceKeyReport($eight)['wrongCount'])->toHaveCount(1);
});

it('refuses a platform token, a terminal token and a token with no employee on every employee route, and the owner passes the guard (AC-18, AC-35)', function () {
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);
    $till = orgHttpActivated($owner, $at, 't1');

    $platform = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(TokenClaims::TYPE_PLATFORM, $world['user']))->accessToken;
    $noEmployee = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: $world['user'],
        tenantId: $world['tenant'],
        employeeClaims: null,
    ))->accessToken;

    $employeeRoutes = array_values(array_filter(orgSurfaceRoutes(), static fn (array $r): bool => $r['class'] === 'employee'));

    expect(count($employeeRoutes))->toBeGreaterThan(20);

    $refusals = [
        'platform' => orgSurfaceStatuses($employeeRoutes, $platform),
        'terminal' => orgSurfaceStatuses($employeeRoutes, $till['access']),
        'no employee' => orgSurfaceStatuses($employeeRoutes, $noEmployee),
    ];

    foreach ($refusals as $who => $statuses) {
        foreach ($statuses as $label => $status) {
            expect(in_array($status, ['403/wrong_token_type', '403/not_permitted', '401/'], true))->toBeTrue("{$who} on {$label} got {$status}");
            expect(str_starts_with($status, '2'))->toBeFalse("{$who} on {$label} got {$status}");
        }
    }

    // The owner is not refused by the guard on any route: the answer is the
    // handler's (a 200, a validation, a not_found, a state refusal) and never
    // the guard's.
    foreach (orgSurfaceStatuses($employeeRoutes, $owner) as $label => $status) {
        expect(in_array($status, ['403/not_permitted', '403/wrong_token_type', '401/'], true))->toBeFalse("owner on {$label} got {$status}");
    }

    fwrite(STDOUT, PHP_EOL.'AC-18 token classes refused on '.count($employeeRoutes).' employee routes: platform, terminal, no employee; owner passes all'.PHP_EOL);
});

it('lets an employee holding only employees.view do none of the organization operations, and an exact-key holder do exactly theirs (AC-53)', function () {
    $world = orgHttpWorld();
    $viewOnly = orgHttpToken($world, orgHttpEmployeeHolding('viewonly', ['employees.view']));
    $employeeRoutes = array_values(array_filter(orgSurfaceRoutes(), static fn (array $r): bool => $r['class'] === 'employee'));

    foreach (orgSurfaceStatuses($employeeRoutes, $viewOnly) as $label => $status) {
        expect($status)->toBe('403/not_permitted', $label);
    }

    // Control: each key, held alone, opens a route that declares it and does
    // not open one that declares another key (one of each, to stay cheap; the
    // view-only case above already covers every route).
    $opened = [];

    foreach (orgSurfaceEightKeys() as $key) {
        $token = orgHttpToken($world, orgHttpEmployeeHolding('holder-'.str_replace('.', '-', $key), [$key]));

        $declaring = array_values(array_filter($employeeRoutes, static fn (array $r): bool => $r['keys'] === [$key]));
        $other = array_values(array_filter($employeeRoutes, static fn (array $r): bool => $r['keys'] !== [$key]));

        $yes = orgSurfaceStatuses([$declaring[0]], $token);
        $no = orgSurfaceStatuses([$other[0]], $token);

        expect(reset($yes))->not->toBe('403/not_permitted', "[{$key}] on its own route");
        expect(reset($no))->toBe('403/not_permitted', "[{$key}] on a route of another key");

        $opened[] = $key;
    }

    expect($opened)->toEqualCanonicalizing(orgSurfaceEightKeys());
});

it('syncs the registry twice into one row per key and records the eight keys (AC-53)', function () {
    $world = orgHttpWorld();

    $counts = orgHttpRead(static function (): array {
        app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);
        app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);

        $rows = AccessSeed::connection()->table('permissions')->pluck('key')->all();

        return array_count_values(array_map('strval', $rows));
    });

    foreach (orgSurfaceEightKeys() as $key) {
        expect($counts[$key] ?? 0)->toBe(1, $key);
    }

    expect(max($counts))->toBe(1);
    expect($world['owner'])->not->toBe('');
});

/**
 * A scratch helper and controller that reach the PIN verification, for the
 * liveness control of the AC-60 scan.
 */
final class QaOrgSurfaceHelper
{
    public function __construct(public readonly VerifyPinHandler $verify) {}
}

final class QaOrgSurfaceController
{
    public function __construct(public readonly QaOrgSurfaceHelper $helper) {}
}

/**
 * Does the code of this file (comments and strings removed) name the PIN
 * verification?
 */
function orgSurfaceNamesVerifyPin(string $source): bool
{
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_contains($token[1], 'VerifyPin')) {
            return true;
        }
    }

    return false;
}

/**
 * The classes reachable from a controller through constructor types, within
 * the application's own namespaces, each flagged when it names the verification.
 *
 * @param  array<string, bool>  $reached
 */
function orgSurfaceWalk(string $class, array &$reached): void
{
    if (isset($reached[$class]) || ! (str_starts_with($class, 'Flexipos\\') || str_starts_with($class, 'App\\') || str_starts_with($class, 'QaOrgSurface')) || ! class_exists($class)) {
        return;
    }

    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();

    $reached[$class] = $file !== false && ! str_contains($class, 'Features\\VerifyPin\\') && orgSurfaceNamesVerifyPin((string) file_get_contents($file));

    $constructor = $reflection->getConstructor();

    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            orgSurfaceWalk($type->getName(), $reached);
        }
    }
}

it('finds no organization or terminal route whose controller graph reaches the PIN verification, and finds a planted one (AC-60)', function () {
    $examined = [];
    $reaching = [];
    $walked = [];

    foreach (orgSurfaceRoutes() as $row) {
        $action = $row['route']->getActionName();
        $class = str_contains($action, '@') ? (string) strstr($action, '@', true) : $action;

        $examined[] = $row['label'];
        $reached = [];

        orgSurfaceWalk($class, $reached);

        foreach ($reached as $reachedClass => $names) {
            $walked[$reachedClass] = true;

            if ($names || $reachedClass === VerifyPinHandler::class) {
                $reaching[] = $row['label'].' reaches '.$reachedClass;
            }
        }
    }

    fwrite(STDOUT, PHP_EOL.'AC-60: '.count($examined).' organization/terminal routes examined, '.count($walked).' classes walked'.PHP_EOL);

    expect(count($examined))->toBeGreaterThan(20);
    expect(count($walked))->toBeGreaterThan(20);
    expect($reaching)->toBe([]);

    // Liveness: a controller that reaches the verification through a helper is reported.
    $planted = [];
    orgSurfaceWalk(QaOrgSurfaceController::class, $planted);

    expect(in_array(true, $planted, true) || isset($planted[VerifyPinHandler::class]))->toBeTrue();
});

it('has no route that moves a revoked terminal back (AC-40)', function () {
    $offending = [];

    foreach (orgSurfaceRoutes() as $row) {
        if (preg_match('/un-?revoke|restore|reinstate|re-?enrol|re-?enroll/i', $row['route']->uri()) === 1) {
            $offending[] = $row['label'];
        }
    }

    expect($offending)->toBe([]);

    Route::middleware('api')->post('api/v1/organization/terminals/{terminal}/unrevoke', fn () => 'x');

    $found = [];

    foreach (orgSurfaceRoutes() as $row) {
        if (preg_match('/un-?revoke/i', $row['route']->uri()) === 1) {
            $found[] = $row['label'];
        }
    }

    expect($found)->toHaveCount(1);
});

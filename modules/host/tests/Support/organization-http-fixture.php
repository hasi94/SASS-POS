<?php

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\json;
use function Pest\Laravel\withServerVariables;

/*
|--------------------------------------------------------------------------
| The world the organization HTTP tests share (T-014 steps 8 to 16)
|--------------------------------------------------------------------------
|
| A master user and shop, the mapping that points the shop at the fixture
| tenant, the permission registry synced into it, and employees whose tokens
| are real: minted through `IssueTokenPairHandler` with the claims read from
| the tenant database, so `RequireTenantToken`'s staleness check passes for
| a reason and not by construction.
|
| It is loaded with `require_once` from each test file that needs it, never
| collected, and its functions carry the `orgHttp` prefix so no two files can
| declare one twice.
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const ORG_HTTP_CLOCK = '2026-10-22 10:00:00.000';

const ORG_HTTP_KID = 'org-http-key';

/**
 * Boot the clock, the master and the key the HTTP tests mint with, and the
 * two fixture tenants. Called from `beforeEach`.
 */
function orgHttpBoot(): void
{
    authFixtureClockAt(ORG_HTTP_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(ORG_HTTP_KID);

    AccessTenantFixture::boot();
}

/**
 * Run something inside the fixture tenant, then leave.
 */
function orgHttpInTenant(callable $work): mixed
{
    AccessTenantFixture::switchTo('tenant-a');

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

/**
 * The master rows and the mapping, the registry synced into the tenant, and
 * an owner employee. Returns the ids the tests need.
 *
 * @return array{user: string, tenant: string, owner: string}
 */
function orgHttpWorld(string $suffix = 'a'): array
{
    $userId = authFixtureUser('owner-'.$suffix.'@example.com');
    $tenantId = authFixtureTenant('SHOP-'.strtoupper($suffix));

    authFixtureMembership($tenantId, $userId);

    $record = tenancyFixtureMapping()['tenant-a'];
    $record['schema_version'] = AccessTenantFixture::topVersion();

    /** @var array<string, array<string, mixed>> $configured */
    $configured = config('tenancy.databases', []);

    config()->set('tenancy.databases', $configured + [$tenantId => $record, 'tenant-a' => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    $owner = orgHttpInTenant(static function () use ($suffix): string {
        /** @var SyncPermissionRegistryHandler $sync */
        $sync = app(SyncPermissionRegistryHandler::class);
        $sync->handle(new SyncPermissionRegistry);

        $employee = AccessSeed::employee('owner-'.$suffix, ['display_name' => 'Owner '.$suffix]);
        $role = (string) AccessSeed::connection()->table('roles')->where('key', 'owner')->value('id');

        AccessSeed::assign($employee, $role);

        return $employee;
    });

    return ['user' => $userId, 'tenant' => $tenantId, 'owner' => $owner];
}

/**
 * An employee holding exactly these keys, company-wide.
 *
 * @param  list<string>  $keys
 */
function orgHttpEmployeeHolding(string $code, array $keys): string
{
    return orgHttpInTenant(static fn (): string => AccessSeed::actorHolding($code, $keys));
}

/**
 * A tenant access token for this employee, with claims read from the tenant.
 *
 * @param  array{user: string, tenant: string}  $world
 */
function orgHttpToken(array $world, string $employeeId): string
{
    $claims = orgHttpInTenant(static fn () => AccessSeed::tokenClaims($employeeId));

    /** @var IssueTokenPairHandler $tokens */
    $tokens = app(IssueTokenPairHandler::class);

    return $tokens->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: $world['user'],
        tenantId: $world['tenant'],
        employeeClaims: $claims,
    ))->accessToken;
}

/**
 * A JSON request with a bearer token.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function orgHttpCall(string $method, string $uri, string $token, array $body = []): TestResponse
{
    return json($method, $uri, $body, ['Authorization' => 'Bearer '.$token]);
}

/**
 * Read tenant rows without leaving the tenant entered.
 *
 * @template T
 *
 * @param  callable(): T  $work
 * @return T
 */
function orgHttpRead(callable $work): mixed
{
    return orgHttpInTenant($work);
}

/*
|--------------------------------------------------------------------------
| Terminals: creating one, and activating it as a till does (T-014 steps 11-13)
|--------------------------------------------------------------------------
*/

/**
 * A branch with its store, created through the API as the owner.
 *
 * @return array{branch: string, store: string}
 */
function orgHttpBranch(string $token, string $code = 'br1'): array
{
    $created = orgHttpCall('POST', '/api/v1/organization/branches', $token, [
        'code' => $code, 'name' => 'Branch '.$code, 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]);

    return ['branch' => (string) $created->json('branch_id'), 'store' => (string) $created->json('location_id')];
}

/**
 * A pending terminal through the API, with the code the response showed once.
 *
 * @param  array{branch: string, store: string}  $at
 * @return array{terminal: string, code: string}
 */
function orgHttpTerminalAt(string $token, array $at, string $code): array
{
    $created = orgHttpCall('POST', '/api/v1/organization/terminals', $token, [
        'branch_id' => $at['branch'], 'location_id' => $at['store'], 'code' => $code, 'name' => 'Till '.$code,
    ]);

    return ['terminal' => (string) $created->json('terminal_id'), 'code' => (string) $created->json('activation_code')];
}

/**
 * A fingerprint: 64 lower-case hex characters from a stem.
 */
function orgHttpFingerprint(string $stem): string
{
    return hash('sha256', 'fingerprint:'.$stem);
}

/**
 * The public activation call, from a chosen caller address.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function orgHttpActivate(array $body, string $ip = '203.0.113.5'): TestResponse
{
    withServerVariables(['REMOTE_ADDR' => $ip]);

    return json('POST', '/api/v1/terminal/activate', $body);
}

/**
 * A refresh call, from a chosen address.
 *
 * @return TestResponse<Response>
 */
function orgHttpRefresh(mixed $refreshToken, string $ip = '203.0.113.5'): TestResponse
{
    withServerVariables(['REMOTE_ADDR' => $ip]);

    return json('POST', '/api/v1/terminal/refresh', ['refresh_token' => $refreshToken]);
}

/**
 * A terminal created and activated: its ids, its two tokens and its
 * fingerprint.
 *
 * @param  array{branch: string, store: string}  $at
 * @return array{terminal: string, branch: string, access: string, refresh: string, fingerprint: string}
 */
function orgHttpActivated(string $ownerToken, array $at, string $code, ?string $fingerprint = null): array
{
    $terminal = orgHttpTerminalAt($ownerToken, $at, $code);
    $fingerprint ??= orgHttpFingerprint($code);

    $activated = orgHttpActivate(['code' => $terminal['code'], 'fingerprint' => $fingerprint, 'platform' => 'Windows']);

    expect($activated->getStatusCode())->toBe(200);

    return [
        'terminal' => $terminal['terminal'],
        'branch' => $at['branch'],
        'access' => (string) $activated->json('access_token'),
        'refresh' => (string) $activated->json('refresh_token'),
        'fingerprint' => $fingerprint,
    ];
}

/**
 * Decode a JWT's claims without verifying it: for asserting what was minted.
 *
 * @return array<string, mixed>
 */
function orgHttpClaims(string $jwt): array
{
    $segment = explode('.', $jwt)[1] ?? '';

    /** @var array<string, mixed> $claims */
    $claims = json_decode((string) base64_decode(strtr($segment, '-_', '+/'), true), true) ?: [];

    return $claims;
}

/**
 * The terminal-only route the middleware tests mount (T-014 step 12): step 14's
 * settings route is the shipped one, and `RequireTerminalToken` has to be
 * reachable on its own. A class, not a closure, so no later route sweep in the
 * same process can trip over it. Declared once here for every file that mounts it.
 */
if (! class_exists('TerminalProbeController', false)) {
    final class TerminalProbeController
    {
        public function __invoke(): JsonResponse
        {
            return new JsonResponse(['ok' => true]);
        }
    }
}

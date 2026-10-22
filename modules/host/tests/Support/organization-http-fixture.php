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
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\json;

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

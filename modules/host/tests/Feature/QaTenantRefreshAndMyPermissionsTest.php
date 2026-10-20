<?php

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\json;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| T-010 step 23 - tenant refresh, my permissions, platform staff
|--------------------------------------------------------------------------
|
| AC-60's HTTP half, AC-62 (the refresh re-reads and does not consume the
| token when it refuses), AC-72 (platform staff get no bypass) and AC-74
| (T-009's operations are unchanged).
|
| **AC-62's refusal needs its liveness control and the criterion says
| why.** "The refresh token was not consumed" is an absence, and a token
| that was never valid also has a null `used_at`. So the same token is
| presented again after the employee is reinstated and must succeed -
| which is the only observation that separates "not consumed" from "not a
| token".
|
| **AC-72's pair is what makes the refusal attributable to the missing
| employee.** Staff with no employee are refused on a guarded route; the
| same user, with a membership linking an owner employee, passes the same
| route. A single refusal would be satisfied by a platform token, an
| expired one, or a shop that is not theirs.
|
| No engine: the master is the in-memory SQLite stand-in and the tenant a
| file under storage/app. Nothing reaches 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const TENANT_REFRESH_CLOCK = '2026-10-20 10:00:00.000';

const TENANT_REFRESH_KID = 'tenant-refresh-key';

const TENANT_REFRESH_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function () {
    authFixtureClockAt(TENANT_REFRESH_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(TENANT_REFRESH_KID);

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * Point a master tenant's mapping at the fixture tenant's database.
 */
function tenantRefreshMapAt(string $tenantId): void
{
    $record = tenancyFixtureMapping()['tenant-a'];
    $record['schema_version'] = AccessTenantFixture::topVersion();

    config()->set('tenancy.databases', [$tenantId => $record, 'tenant-a' => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $route->flushController();
    }
}

/**
 * Run something inside the fixture tenant, then leave (Ruling 7).
 */
function tenantRefreshInTenant(callable $work): mixed
{
    AccessTenantFixture::switchTo('tenant-a');

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

/**
 * The claims of a signed token, unverified.
 *
 * @return array<string, mixed>
 */
function tenantRefreshClaimsOf(string $token): array
{
    /** @var array<string, mixed> $claims */
    $claims = json_decode(
        (string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true),
        true
    );

    return $claims;
}

/**
 * A user, a shop mapped at the fixture tenant, an employee linked to the
 * user, and a selected tenant token pair.
 *
 * @param  array<string, mixed>  $userAttributes
 * @return array<string, mixed>
 */
function tenantRefreshWorld(bool $withEmployee = true, array $userAttributes = []): array
{
    $userId = authFixtureUser('owner@example.com', TENANT_REFRESH_PASSWORD, $userAttributes);
    $tenantId = authFixtureTenant('shop-a');

    tenantRefreshMapAt($tenantId);

    // The registry, synced into the shop, so a fixture can seed an actor
    // holding a real key: `role_permissions.permission_key` is a foreign
    // key and `roles.view` is a row only the sync writes (Ruling 7 - the
    // fixture connection enforces it, which is how this was found).
    tenantRefreshInTenant(static function (): void {
        /** @var SyncPermissionRegistryHandler $sync */
        $sync = app(SyncPermissionRegistryHandler::class);
        $sync->handle(new SyncPermissionRegistry);
    });

    $employeeId = $withEmployee
        ? tenantRefreshInTenant(static function () use ($userId): string {
            AccessSeed::permission('fixture.company');

            $id = AccessSeed::employee('owner', ['user_id' => $userId]);

            AccessSeed::assign($id, AccessSeed::role('fixture_owner', ['fixture.company']));

            return $id;
        })
        : null;

    authFixtureMembership($tenantId, $userId, $withEmployee ? ['employee_id' => $employeeId] : []);

    $login = postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => TENANT_REFRESH_PASSWORD,
    ])->assertOk()->json();

    $selected = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], [
        'Authorization' => 'Bearer '.$login['access_token'],
    ])->assertOk()->json();

    return [
        'user' => $userId,
        'tenant' => $tenantId,
        'employee' => $employeeId,
        'access' => $selected['access_token'],
        'refresh' => $selected['refresh_token'],
    ];
}

it('re-reads the claims on a tenant refresh rather than copying the old ones (AC-62)', function () {
    $world = tenantRefreshWorld();

    $before = tenantRefreshClaimsOf((string) $world['access']);

    // The employee's access changes after the token was issued: a second
    // grant, a new attachment and the bump that goes with them.
    $branchX = tenantRefreshInTenant(static function () use ($world): string {
        $branch = AccessSeed::branch();

        AccessSeed::permission('fixture.second');
        AccessSeed::attach((string) $world['employee'], $branch);
        AccessSeed::assign(
            (string) $world['employee'],
            AccessSeed::role('fixture_second', ['fixture.second']),
            $branch
        );
        AccessSeed::connection()->table('employees')
            ->where('id', $world['employee'])
            ->update(['perm_version' => AccessSeed::permVersion((string) $world['employee']) + 1]);

        return $branch;
    });

    $refreshed = postJson('/api/v1/auth/refresh', ['refresh_token' => $world['refresh']])->assertOk()->json();
    $after = tenantRefreshClaimsOf((string) $refreshed['access_token']);

    expect($after['perm_version'])->toBe($before['perm_version'] + 1)
        ->and($after['perm_hash'])->not->toBe($before['perm_hash'])
        ->and($after['branch_ids'])->toBe([$branchX])
        ->and($before['branch_ids'])->toBe([])
        ->and($after['all_branches'])->toBeTrue()
        ->and(TokenClaims::isExactly($after, TokenClaims::TYPE_TENANT))->toBeTrue();
});

it('refuses a refresh for a non-acting employee without consuming the token, and the same token works afterwards (AC-62)', function () {
    $world = tenantRefreshWorld();

    $usedAt = static fn (): mixed => DB::connection('master')
        ->table('refresh_tokens')
        ->orderByDesc('created_at')
        ->value('used_at');

    tenantRefreshInTenant(static function () use ($world): void {
        AccessSeed::connection()->table('employees')
            ->where('id', $world['employee'])
            ->update(['status' => 'on_leave']);
    });

    postJson('/api/v1/auth/refresh', ['refresh_token' => $world['refresh']])
        ->assertForbidden()
        ->assertJson(['error' => 'employee_unavailable']);

    // D-24: the refusal consumed nothing. On its own this is satisfied by
    // a token that was never valid, which is why the control below is
    // the other half of the criterion rather than a nicety.
    expect($usedAt())->toBeNull();

    tenantRefreshInTenant(static function () use ($world): void {
        AccessSeed::connection()->table('employees')
            ->where('id', $world['employee'])
            ->update(['status' => 'active']);
    });

    postJson('/api/v1/auth/refresh', ['refresh_token' => $world['refresh']])->assertOk();

    // And it is consumed now, which is what makes the null above mean
    // "untouched" rather than "this column is always null".
    expect(DB::connection('master')->table('refresh_tokens')->whereNotNull('used_at')->count())->toBe(1);
});

it('writes a login_history failure row naming the tenant when a refresh is refused (AC-61, AC-62)', function () {
    $world = tenantRefreshWorld();

    tenantRefreshInTenant(static function () use ($world): void {
        AccessSeed::connection()->table('employees')
            ->where('id', $world['employee'])
            ->update(['status' => 'on_leave']);
    });

    $before = DB::connection('master')->table('login_history')->count();

    postJson('/api/v1/auth/refresh', ['refresh_token' => $world['refresh']])->assertForbidden();

    $rows = DB::connection('master')->table('login_history')->orderByDesc('id')->get();

    expect(DB::connection('master')->table('login_history')->count() - $before)->toBe(1)
        ->and((string) $rows[0]->failure_reason)->toBe('employee_unavailable')
        ->and((string) $rows[0]->tenant_id)->toBe((string) $world['tenant'])
        ->and((bool) $rows[0]->success)->toBeFalse();
});

it('leaves a platform refresh untouched by any employee state (AC-62)', function () {
    $world = tenantRefreshWorld();

    $login = postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => TENANT_REFRESH_PASSWORD,
    ])->assertOk()->json();

    tenantRefreshInTenant(static function () use ($world): void {
        AccessSeed::connection()->table('employees')
            ->where('id', $world['employee'])
            ->update(['status' => 'terminated']);
    });

    // A platform refresh token is not tenant-scoped, so no employee is
    // read for it at all - the same employee state that refuses the
    // tenant refresh above.
    $refreshed = postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertOk()->json();

    expect(TokenClaims::namesOf(tenantRefreshClaimsOf((string) $refreshed['access_token'])))
        ->toBe(['exp', 'iat', 'jti', 'sub', 'token_type']);
});

it('ignores any perm_ or employee field a refresh request submits (AC-62, T-009 AC-38)', function () {
    $world = tenantRefreshWorld();

    $refreshed = postJson('/api/v1/auth/refresh', [
        'refresh_token' => $world['refresh'],
        'perm_version' => 9999,
        'perm_hash' => str_repeat('f', 64),
        'employee' => '01JBZZZZZZZZZZZZZZZZZZZZZZ',
        'branch_ids' => ['01JBZZZZZZZZZZZZZZZZZZZZZZ'],
        'all_branches' => true,
    ])->assertOk()->json();

    $claims = tenantRefreshClaimsOf((string) $refreshed['access_token']);

    expect($claims['perm_version'])->toBe(1)
        ->and($claims['employee'])->toBe($world['employee'])
        ->and($claims['branch_ids'])->toBe([])
        ->and($claims['all_branches'])->toBeTrue()
        ->and($claims['perm_hash'])->not->toBe(str_repeat('f', 64));
});

it('answers my permissions live, on the one route that needs no key (AC-60)', function () {
    $world = tenantRefreshWorld();

    $headers = ['Authorization' => 'Bearer '.$world['access']];

    json('GET', '/api/v1/access/me/permissions', [], $headers)
        ->assertOk()
        ->assertJson([
            'perm_version' => 1,
            'all_branches' => true,
            'branch_ids' => [],
            'permissions' => [['key' => 'fixture.company', 'scope' => '*', 'allowed' => true]],
        ]);
});

it('answers an empty set, with no perm values, for a token with no employee claim (AC-60)', function () {
    $world = tenantRefreshWorld(withEmployee: false);

    json('GET', '/api/v1/access/me/permissions', [], ['Authorization' => 'Bearer '.$world['access']])
        ->assertOk()
        ->assertExactJson([
            'perm_version' => null,
            'perm_hash' => null,
            'branch_ids' => [],
            'all_branches' => false,
            'permissions' => [],
        ]);
});

it('gives platform staff with no employee no way past any guarded route, and the control passes (AC-72)', function () {
    // T-009 D-18: platform staff are ordinary members of a shop. The flag
    // buys nothing here, and the way to show that is to run the same
    // route twice for the same flagged user.
    $world = tenantRefreshWorld(withEmployee: false, userAttributes: ['is_platform_staff' => true]);

    $headers = ['Authorization' => 'Bearer '.$world['access']];

    expect(tenantRefreshClaimsOf((string) $world['access']))->not->toHaveKey('employee');

    json('GET', '/api/v1/access/roles', [], $headers)
        ->assertForbidden()
        ->assertJson(['error' => 'not_permitted']);

    json('GET', '/api/v1/access/employees', [], $headers)
        ->assertForbidden()
        ->assertJson(['error' => 'not_permitted']);

    // The one route such a token does reach, which is also what makes
    // the two refusals above refusals of the permission check rather
    // than of the token.
    json('GET', '/api/v1/access/me/permissions', [], $headers)->assertOk();

    // The control: the same flagged user, now with a membership linking
    // an owner employee, passes the same guarded route.
    $employeeId = tenantRefreshInTenant(static function (): string {
        return AccessSeed::actorHolding('owner', ['roles.view'], null, true);
    });

    tenantRefreshInTenant(static function () use ($employeeId, $world): void {
        AccessSeed::connection()->table('employees')
            ->where('id', $employeeId)
            ->update(['user_id' => $world['user']]);
    });

    DB::connection('master')->table('tenant_users')
        ->where('tenant_id', $world['tenant'])
        ->update(['employee_id' => $employeeId]);

    $login = postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => TENANT_REFRESH_PASSWORD,
    ])->assertOk()->json();

    $selected = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], [
        'Authorization' => 'Bearer '.$login['access_token'],
    ])->assertOk()->json();

    json('GET', '/api/v1/access/roles', [], ['Authorization' => 'Bearer '.$selected['access_token']])
        ->assertOk();
});

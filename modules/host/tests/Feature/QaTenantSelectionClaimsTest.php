<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Flexipos\Kernel\Tenancy\Exceptions\TenantSchemaBehind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| T-010 step 22 - the claims a selection mints, end to end
|--------------------------------------------------------------------------
|
| AC-56 (the exact claim set, through the real endpoint), AC-58 (all five
| or none, and a null-employee membership still opens no tenant database)
| and AC-59 (an employee-bearing membership does open it, through the
| existing gate).
|
| **It lives here and not in `packages/auth/tests` because of a boundary,
| not a preference.** The positive half of AC-56 needs a migrated tenant
| database, and the fixture that provides one is
| `Flexipos\Access\Tests\Support\AccessTenantFixture` - inside
| `Package_Access`, which `Package_Auth` may not reference. That missing
| edge is AC-11's instrument and is not widened for a test. The host's own
| test layer may reference both packages, so this is where the two sides
| meet. `SelectTenantTest` keeps the half it can still prove alone and
| names this file for the other.
|
| **The claim set is compared as a set, never as a containment** (AC-56,
| T-009 AC-28). A token with one extra claim fails the same comparison,
| which is asserted here rather than assumed, and the **platform** token's
| set is asserted unchanged - the one thing T-010 must not have moved.
|
| **The hash is recomputed in the test from the stored rows**, not read
| back from the thing that produced it: a claim that agreed with itself
| would be satisfied by a minter that wrote any 64 hex characters.
|
| No engine: the master is the in-memory SQLite stand-in and the tenant a
| file under storage/app. Nothing reaches 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const SELECTION_CLOCK = '2026-10-20 10:00:00.000';

const SELECTION_KID = 'selection-claims-key';

const SELECTION_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function () {
    authFixtureClockAt(SELECTION_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(SELECTION_KID);

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
function selectionMapAt(string $tenantId, int $schemaVersion): void
{
    $record = tenancyFixtureMapping()['tenant-a'];
    $record['schema_version'] = $schemaVersion;

    config()->set('tenancy.databases', [$tenantId => $record, 'tenant-a' => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    // **And the router's cached controllers, which is the part that is
    // easy to miss and silently inverts a control.** A `Route` memoises
    // its controller instance after its first use, and that instance
    // holds the handler it was built with, which holds a `Tenancy`,
    // which holds a mapping repository built from the config as it was
    // then. Re-mapping without this leaves the second request answering
    // from the first request's fleet: measured here as a servable tenant
    // still being refused 503 after the mapping had been repaired. In
    // production each request builds its own container and the question
    // does not arise.
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $route->flushController();
    }
}

/**
 * Run something inside the fixture tenant, then leave (Ruling 7).
 */
function selectionInTenant(callable $work): mixed
{
    AccessTenantFixture::switchTo('tenant-a');

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

/**
 * A platform bearer header for a freshly logged-in user.
 *
 * @return array<string, string>
 */
function selectionBearer(string $email): array
{
    $login = postJson('/api/v1/auth/login', ['email' => $email, 'password' => SELECTION_PASSWORD])
        ->assertOk()
        ->json();

    return ['Authorization' => 'Bearer '.$login['access_token']];
}

/**
 * The claims of a signed token, without verifying it.
 *
 * @return array<string, mixed>
 */
function selectionClaimsOf(string $token): array
{
    $segments = explode('.', $token);

    /** @var array<string, mixed> $claims */
    $claims = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true);

    return $claims;
}

it('mints exactly AC-56 s claim set for an employee-bearing membership', function () {
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    selectionMapAt($tenantId, AccessTenantFixture::topVersion());

    $branchX = selectionInTenant(static fn (): string => AccessSeed::branch());

    $employeeId = selectionInTenant(static function () use ($userId, $branchX): string {
        AccessSeed::permission('fixture.company');

        $id = AccessSeed::employee('owner', ['user_id' => $userId]);

        AccessSeed::attach($id, $branchX);
        AccessSeed::assign($id, AccessSeed::role('fixture_owner', ['fixture.company']));
        AccessSeed::connection()->table('employees')->where('id', $id)->update(['perm_version' => 5]);

        return $id;
    });

    authFixtureMembership($tenantId, $userId, ['employee_id' => $employeeId]);

    $bearer = selectionBearer('owner@example.com');

    $selected = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer)->assertOk()->json();
    $claims = selectionClaimsOf($selected['access_token']);

    // Set equality, sorted: "contains" would pass a token carrying more.
    expect(TokenClaims::namesOf($claims))->toBe([
        'all_branches',
        'branch_ids',
        'employee',
        'exp',
        'iat',
        'jti',
        'perm_hash',
        'perm_version',
        'sub',
        'tenant',
        'token_type',
    ])
        ->and($claims['employee'])->toBe($employeeId)
        ->and($claims['tenant'])->toBe($tenantId)
        ->and($claims['branch_ids'])->toBe([$branchX])
        ->and($claims['all_branches'])->toBeTrue()
        ->and($claims['perm_version'])->toBe(5);

    // Recomputed from the canonical form of Ruling 6 rather than read
    // back from the helper that minted it.
    $expected = bin2hex(hash(
        'sha256',
        "flexipos.perm.v1\n5\n".'allow'."\t".'fixture.company'."\t".'*',
        true
    ));

    expect($claims['perm_hash'])->toBe($expected);

    // The negative control AC-56 names: the same comparison refuses one
    // extra claim, so the equality above is doing work.
    expect(TokenClaims::isExactly($claims, TokenClaims::TYPE_TENANT))->toBeTrue()
        ->and(TokenClaims::isExactly($claims + ['database' => 'x'], TokenClaims::TYPE_TENANT))->toBeFalse();
});

it('leaves the platform claim set exactly as T-009 minted it (AC-56)', function () {
    authFixtureUser('owner@example.com');

    $login = postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => SELECTION_PASSWORD,
    ])->assertOk()->json();

    expect(TokenClaims::namesOf(selectionClaimsOf($login['access_token'])))
        ->toBe(['exp', 'iat', 'jti', 'sub', 'token_type']);
});

it('mints none of the five, and opens no tenant database, for a membership with no employee (AC-58)', function () {
    $userId = authFixtureUser('staff@example.com');
    $tenantId = authFixtureTenant('shop-b');

    authFixtureMembership($tenantId, $userId);

    // Deliberately NOT mapped: a selection that opened the tenant would
    // fail here with `TenantDatabaseNotMapped`, so "no database was
    // opened" is a property of the run rather than an observation that
    // could be made of a mapped tenant too.
    config()->set('tenancy.databases', []);
    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();

    $before = config('database.connections.tenant');

    $selected = postJson('/api/v1/auth/tenant', ['code' => 'shop-b'], selectionBearer('staff@example.com'))
        ->assertOk()
        ->json();

    $claims = selectionClaimsOf($selected['access_token']);

    foreach (['employee', 'branch_ids', 'all_branches', 'perm_version', 'perm_hash'] as $claim) {
        expect(array_key_exists($claim, $claims))->toBeFalse("[{$claim}] must be absent (AC-58)");
    }

    expect(TokenClaims::namesOf($claims))->toBe(['exp', 'iat', 'jti', 'sub', 'tenant', 'token_type'])
        // T-009 AC-52's other half: the stanza is the placeholder it was.
        ->and(config('database.connections.tenant'))->toBe($before)
        ->and(config('database.connections.tenant.driver'))->toBe('flexipos-no-tenant');
});

it('is refused by the existing serving gate, with no token and no refresh row, and succeeds once servable (AC-59)', function () {
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    selectionMapAt($tenantId, AccessTenantFixture::topVersion());

    $employeeId = selectionInTenant(static fn (): string => AccessSeed::employee('owner', ['user_id' => $userId]));

    authFixtureMembership($tenantId, $userId, ['employee_id' => $employeeId]);

    $bearer = selectionBearer('owner@example.com');
    $refreshRows = static fn (): int => DB::connection('master')->table('refresh_tokens')->count();

    $before = $refreshRows();

    // Below the schema floor: the kernel's gate refuses, and this task
    // does not reimplement it. The refusal is deliberately NOT caught and
    // turned into a 403 - T-009's `RequireTenantToken` makes the same
    // call in the same words, because a tenant that cannot be served is a
    // deployment problem and a 403 would make it look like a credential
    // one. What AC-59 asks for in substance holds: no token, no refresh
    // row, and the identical request succeeds once the tenant is
    // servable.
    selectionMapAt($tenantId, 0);

    postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer)
        ->assertStatus(503)
        ->assertHeader('Retry-After')
        ->assertJson(['message' => TenantSchemaBehind::PUBLIC_MESSAGE]);

    expect($refreshRows())->toBe($before);

    // The control: the same request, the same employee, servable.
    selectionMapAt($tenantId, AccessTenantFixture::topVersion());

    postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer)->assertOk();

    expect($refreshRows())->toBe($before + 1);
});

<?php

use App\Http\Middleware\RequireTenantPermission;
use App\Http\Middleware\RequireTenantToken;
use Firebase\JWT\JWT;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Keys\KeyPairGenerator;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Contracts\Access\EmployeeStalenessCheck;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-010 step 21 - the staleness check inside RequireTenantToken
|--------------------------------------------------------------------------
|
| AC-66 (a token whose `perm_version` is behind the row is 401
| `permissions_changed`, no handler, no `access_log` row), AC-67 (the
| window is closed for permissions and deliberately open for master users)
| and AC-68 (the serving gate refuses before the check runs).
|
| **The tokens here are signed by this file, and that is step 21's
| position in the plan rather than a shortcut.** The five employee-derived
| claims are minted by `SelectTenant` from step 22 onward; until then
| nothing in the application produces a token carrying `perm_version`, so
| a test of the check that waits for the minter would be a test of
| nothing. The claims are written the way AC-56 defines them and the token
| is signed with the fixture's own key through the real algorithm, so what
| the middleware verifies is a real token. Step 22 then asserts that the
| minter produces exactly this shape.
|
| **The spy counts to one before it counts to zero** (shape 5). "No
| handler ran" is what a broken route, a wrong path and an uncalled test
| all report, so every absence here is preceded by the same spy observing
| the same route being reached with a fresh token.
|
| **AC-68 needs the diverging input** (shape 6). A servable tenant with a
| stale token cannot tell "the gate runs first" from "the check runs
| first": both orders refuse. The discriminating fixture is a tenant whose
| mapping is NOT servable AND whose token is stale, and the observation is
| a counting decorator around the staleness check - which owes its own
| liveness control, so the same decorator is shown counting 1 on the
| servable case.
|
| No engine: the master is the in-memory SQLite stand-in and the tenant a
| file under storage/app. Nothing reaches 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const STALE_CLOCK = '2026-10-20 10:00:00.000';

const STALE_KID = 'stale-token-key';

/**
 * The scratch tenant route every case below calls.
 *
 * `authenticated_only`, deliberately: AC-66 is about the token and not
 * about a grant, and a guarded route would let the permission guard refuse
 * first and make every 401 below indistinguishable from a 403 that never
 * happened.
 */
const STALE_ROUTE = '/t10/staleness-probe';

beforeEach(function () {
    authFixtureClockAt(STALE_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(STALE_KID);

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A master user, a shop and a mapping pointing it at the fixture tenant.
 *
 * @return array<string, string>
 */
function staleWorld(): array
{
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, $userId);

    staleMapAt($tenantId, AccessTenantFixture::topVersion());

    return ['user' => $userId, 'tenant' => $tenantId];
}

/**
 * Point the shop's mapping at the fixture tenant's database at a schema
 * version.
 *
 * A version below the floor is AC-68's diverging half: the mapping is then
 * not servable and `Tenancy::enter()` refuses it, which is the gate this
 * task does not reimplement. It is a separate function from
 * {@see staleWorld()} because the seeding a test does beforehand has to
 * happen while the tenant is still enterable.
 */
function staleMapAt(string $tenantId, int $schemaVersion): void
{
    $mapping = tenancyFixtureMapping();
    $record = $mapping['tenant-a'];
    $record['schema_version'] = $schemaVersion;

    config()->set('tenancy.databases', [$tenantId => $record, 'tenant-a' => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();
}

/**
 * Run something inside the fixture tenant, then leave.
 *
 * Through {@see AccessTenantFixture::enter()} (Ruling 7), which is the only
 * path on which the tenant tree's foreign keys are enforced.
 */
function staleInTenant(callable $work): mixed
{
    AccessTenantFixture::switchTo('tenant-a');

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

/**
 * A signed tenant access token carrying whichever claims are asked for.
 *
 * The claim set of AC-56, assembled here rather than taken from
 * `TokenClaims`, so that a later change to the minter cannot make this
 * file agree with it by construction.
 *
 * @param  array<string, mixed>  $extra  the employee-derived claims, or none
 */
function staleToken(string $userId, string $tenantId, array $extra = []): string
{
    $issuedAt = strtotime(STALE_CLOCK);

    $claims = array_merge([
        'sub' => $userId,
        'token_type' => 'tenant',
        'iat' => $issuedAt,
        'exp' => $issuedAt + 900,
        'jti' => 'stale-fixture-jti',
        'tenant' => $tenantId,
    ], $extra);

    return JWT::encode(
        $claims,
        KeyPairGenerator::generate('default')['private'],
        SigningKeyring::ALGORITHM,
        STALE_KID
    );
}

/**
 * The five employee-derived claims for an employee, read live.
 *
 * `perm_version` is the stored one, so a token built from this is fresh by
 * construction; a stale one is made by bumping the row afterwards, which
 * is what actually happens in production.
 *
 * @return array<string, mixed>
 */
function staleClaimsFor(string $employeeId): array
{
    /** @var int $version */
    $version = staleInTenant(static fn (): int => AccessSeed::permVersion($employeeId));

    return [
        'employee' => $employeeId,
        'branch_ids' => [],
        'all_branches' => false,
        'perm_version' => $version,
        'perm_hash' => str_repeat('0', 64),
    ];
}

/**
 * Register the scratch route and return the call counter.
 *
 * @return array{0: Closure(): int}
 */
function staleRouteSpy(): array
{
    $calls = 0;

    Route::middleware([
        'api',
        RequireTenantToken::class,
        RequireTenantPermission::class.':'.RequireTenantPermission::AUTHENTICATED_ONLY,
    ])->get(STALE_ROUTE, function () use (&$calls) {
        $calls++;

        return response()->json(['ok' => true]);
    });

    // A closure that reads `$calls` BY REFERENCE. An arrow function
    // captures by value, so `fn () => $calls` would hand back the zero
    // the counter held when the route was registered - and every
    // "the handler ran once" assertion would read 0 while the handler
    // really had run. Measured here before it could be believed.
    return [static function () use (&$calls): int {
        return $calls;
    }];
}

/**
 * Every `access_log` row in the fixture tenant.
 *
 * @return list<array<string, mixed>>
 */
function staleAccessLogRows(): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = staleInTenant(static fn (): array => AccessSeed::connection()->table('access_log')
        ->get()
        ->map(static fn ($row): array => (array) $row)
        ->all());

    return $rows;
}

it('refuses a token whose perm_version is behind the row, with no handler and no log row (AC-66)', function () {
    $world = staleWorld();
    [$calls] = staleRouteSpy();

    $employeeId = staleInTenant(static fn (): string => AccessSeed::employee('cashier'));
    $token = staleToken($world['user'], $world['tenant'], staleClaimsFor($employeeId));
    $headers = ['Authorization' => 'Bearer '.$token];

    // The liveness half, and it comes first: the same token on the same
    // route reaches the handler exactly once. An uncalled spy is also what
    // a wrong path produces.
    json('GET', STALE_ROUTE, [], $headers)->assertOk();

    expect($calls())->toBe(1);

    // The permission moved under the token.
    staleInTenant(static function () use ($employeeId): void {
        AccessSeed::connection()->table('employees')
            ->where('id', $employeeId)
            ->update(['perm_version' => AccessSeed::permVersion($employeeId) + 1]);
    });

    json('GET', STALE_ROUTE, [], $headers)
        ->assertStatus(401)
        ->assertJson(['error' => 'permissions_changed']);

    // Still one: the handler did not run the second time.
    expect($calls())->toBe(1)
        // A stale credential is not a denial (D-7, AC-66, AC-69).
        ->and(staleAccessLogRows())->toBe([]);

    // And the other control AC-66 names: a token carrying the new version
    // reaches the handler again, so the refusal was about the version and
    // not about the route having broken.
    json('GET', STALE_ROUTE, [], [
        'Authorization' => 'Bearer '.staleToken($world['user'], $world['tenant'], staleClaimsFor($employeeId)),
    ])->assertOk();

    expect($calls())->toBe(2);
});

it('refuses the same way when the employee row is missing or soft-deleted (AC-66)', function () {
    $world = staleWorld();
    [$calls] = staleRouteSpy();

    $employeeId = staleInTenant(static fn (): string => AccessSeed::employee('cashier'));
    $claims = staleClaimsFor($employeeId);

    json('GET', STALE_ROUTE, [], ['Authorization' => 'Bearer '.staleToken($world['user'], $world['tenant'], $claims)])
        ->assertOk();

    expect($calls())->toBe(1);

    // Soft-deleted: the row is still there and the version still matches,
    // so only the deletion can account for the refusal.
    staleInTenant(static function () use ($employeeId): void {
        AccessSeed::connection()->table('employees')
            ->where('id', $employeeId)
            ->update(['deleted_at' => '2026-10-20 10:30:00.000']);
    });

    json('GET', STALE_ROUTE, [], ['Authorization' => 'Bearer '.staleToken($world['user'], $world['tenant'], $claims)])
        ->assertStatus(401)
        ->assertJson(['error' => 'permissions_changed']);

    // An employee id naming no row at all - the same answer, because the
    // credential names somebody this database does not serve.
    json('GET', STALE_ROUTE, [], [
        'Authorization' => 'Bearer '.staleToken($world['user'], $world['tenant'], array_merge($claims, [
            'employee' => '01JBZZZZZZZZZZZZZZZZZZZZZZ',
        ])),
    ])->assertStatus(401)->assertJson(['error' => 'permissions_changed']);

    expect($calls())->toBe(1)
        ->and(staleAccessLogRows())->toBe([]);
});

it('performs no version check for a token with no employee claim (AC-66)', function () {
    $world = staleWorld();
    [$calls] = staleRouteSpy();

    // No employee claim at all: T-009 D-18's token, which is valid for an
    // `authenticated_only` route and for nothing else.
    json('GET', STALE_ROUTE, [], ['Authorization' => 'Bearer '.staleToken($world['user'], $world['tenant'])])
        ->assertOk();

    expect($calls())->toBe(1);
});

it('leaves the master-user window open exactly as T-009 left it (AC-67)', function () {
    $world = staleWorld();
    [$calls] = staleRouteSpy();

    $employeeId = staleInTenant(static fn (): string => AccessSeed::employee('cashier'));
    $headers = ['Authorization' => 'Bearer '.staleToken($world['user'], $world['tenant'], staleClaimsFor($employeeId))];

    json('GET', STALE_ROUTE, [], $headers)->assertOk();

    // The master user is disabled after the token was issued. T-009 AC-48
    // says the token still verifies until `exp`, and this task does not
    // change that: the asymmetry is D-31 and is deliberate.
    expect(DB::connection('master')->table('users')->where('id', $world['user'])->update(['status' => 'disabled']))
        ->toBe(1);

    json('GET', STALE_ROUTE, [], $headers)->assertOk();

    expect($calls())->toBe(2);
});

it('refuses at the serving gate before the staleness check runs (AC-68)', function () {
    $world = staleWorld();
    [$calls] = staleRouteSpy();

    // Seeded while the tenant is still enterable, because the diverging
    // input below makes it unenterable on purpose.
    $employeeId = staleInTenant(static fn (): string => AccessSeed::employee('cashier'));
    $fresh = staleClaimsFor($employeeId);

    // A counting decorator around the real check. It is a probe, so it
    // owes a liveness control, which is the second half below.
    $asked = 0;
    $count = static function () use (&$asked): void {
        $asked++;
    };

    /** @var EmployeeStalenessCheck $real */
    $real = app(EmployeeStalenessCheck::class);

    app()->instance(EmployeeStalenessCheck::class, new class($real, $count) implements EmployeeStalenessCheck
    {
        public function __construct(
            private readonly EmployeeStalenessCheck $inner,
            private readonly Closure $count,
        ) {}

        public function isStale(string $employeeId, int $claimedPermVersion): bool
        {
            ($this->count)();

            return $this->inner->isStale($employeeId, $claimedPermVersion);
        }
    });

    // The diverging input: the mapping is below the schema floor AND the
    // token is stale. A servable tenant cannot discriminate the ordering,
    // because both orders refuse.
    staleMapAt($world['tenant'], 0);

    $stale = array_merge($fresh, ['perm_version' => 999]);

    $response = json('GET', STALE_ROUTE, [], [
        'Authorization' => 'Bearer '.staleToken($world['user'], $world['tenant'], $stale),
    ]);

    expect($response->getStatusCode())->not->toBe(401, 'the serving gate answers, not the staleness check')
        ->and($response->json('error'))->not->toBe('permissions_changed')
        ->and($asked)->toBe(0)
        ->and($calls())->toBe(0);

    // Liveness: the same decorator, the same route, the same token - and
    // with the mapping servable again it is asked exactly once. Without
    // this, `$asked === 0` is equally consistent with a probe that was
    // never wired to anything.
    staleMapAt($world['tenant'], AccessTenantFixture::topVersion());

    json('GET', STALE_ROUTE, [], [
        'Authorization' => 'Bearer '.staleToken($world['user'], $world['tenant'], $fresh),
    ])->assertOk();

    expect($asked)->toBe(1)
        ->and($calls())->toBe(1);
});

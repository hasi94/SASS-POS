<?php

use App\Http\Middleware\RequirePlatformToken;
use App\Http\Middleware\RequireTenantToken;
use App\Http\Responses\AuthRefusals;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Support\GenericRefusal;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| T-009 step 12: the tenant middleware - what it reads, and what it refuses
|--------------------------------------------------------------------------
|
| AC-45 and AC-46. RequireTenantToken verifies the token and enters the
| tenant in one class, so these two criteria are about one object.
|
| **The spy is the instrument and it is asserted in both directions.** A
| middleware test driven by a valid token cannot tell "the middleware
| verified it" from "the middleware let everything through": both answer
| 200. So every refusal case asserts the handler was **never** called, and
| the same spy, in the same test, must be called **exactly once** by a valid
| token - the count is cumulative and is read after each case, which is what
| makes "never" mean "the probe was watching and saw nothing" rather than
| "the probe was never wired up" (plan section 7, shape 5).
|
| **AC-45's discriminating input is named here so a later reader does not
| simplify it away** (plan section 7, shape 6). A request whose token says
| tenant A and whose body, query string and headers all say tenant B - and
| name a database - is the only input on which "the tenant comes from the
| token" and "the tenant comes from the request" produce different
| observable answers. The observable is not configuration: it is the value
| in the `marker` table of the SQLite file the connection actually opened,
| which says `tenant-a` or `tenant-b` and cannot say both. The control is
| the same request with those values removed, which must still resolve A.
|
| **The tenant ids are the master's ULIDs, not the fixture's nicknames.**
| `refresh_tokens.tenant_id` carries a foreign key to `tenants`, so a tenant
| token can only be issued for a shop that exists in the master - and the
| mapping is then keyed by that id, which is how a deployment is keyed too.
| T-003's fixture databases are reused underneath; only the keys move.
|
| No engine: the master is the suite's in-memory SQLite stand-in and both
| tenants are SQLite files under storage/app. Nothing here reaches
| 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const TENANT_MIDDLEWARE_CLOCK = '2026-10-16 09:15:00.000';

const TENANT_MIDDLEWARE_KID = 'tenant-middleware-key';

const TENANT_PROBE_URI = '/t9/tenant-probe';

/**
 * The handler behind the guarded route: a counter, and the shop the request
 * could actually read when it ran.
 */
final class TenantProbeSpy
{
    public int $calls = 0;

    public ?string $resolvedTenantId = null;

    public ?string $markerRead = null;
}

/**
 * Register the one tenant-scoped route this file needs, behind the
 * middleware under test, and return the spy its handler writes to.
 *
 * A closure action is used deliberately: the route exists for the length of
 * one test and must never be part of the application's own route table. The
 * T-004 AC-13 rule it would otherwise break is a rule about
 * `modules/host/routes/web.php`, which this is not.
 */
function tenantProbeSpy(): TenantProbeSpy
{
    $spy = new TenantProbeSpy;

    Route::middleware(RequireTenantToken::class)->post(TENANT_PROBE_URI, function () use ($spy) {
        $spy->calls++;
        $spy->resolvedTenantId = app(Tenancy::class)->current()->tenantId();
        $spy->markerRead = (string) DB::connection('tenant')->scalar('select value from marker');

        return response()->json(['tenant' => $spy->resolvedTenantId]);
    });

    return $spy;
}

/**
 * A signed access token of the given type for the given principal.
 */
function tenantMiddlewareToken(string $tokenType, string $userId, ?string $tenantId = null): string
{
    return app(IssueTokenPairHandler::class)->handle(
        new IssueTokenPair(tokenType: $tokenType, userId: $userId, tenantId: $tenantId)
    )->accessToken;
}

/**
 * Point the tenancy mapping at T-003's two fixture databases, keyed by the
 * master ids the tokens will carry.
 *
 * @param  array<string, string>  $byId  tenant id => fixture nickname
 */
function tenantMiddlewareMapping(array $byId): void
{
    $fixture = tenancyFixtureMapping();
    $mapping = [];

    foreach ($byId as $tenantId => $nickname) {
        $mapping[$tenantId] = $fixture[$nickname];
    }

    config()->set('tenancy.databases', $mapping);

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();
}

beforeEach(function () {
    authFixtureClockAt(TENANT_MIDDLEWARE_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(TENANT_MIDDLEWARE_KID);
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

afterAll(function () {
    tenancyDeleteFixtureRoot();
});

it('resolves the tenant the token names and never the one the request names', function () {
    // AC-45.
    $userId = authFixtureUser('owner@example.com');
    $tenantA = authFixtureTenant('shop-a');
    $tenantB = authFixtureTenant('shop-b');

    authFixtureMembership($tenantA, $userId);
    tenantMiddlewareMapping([$tenantA => 'tenant-a', $tenantB => 'tenant-b']);

    $token = tenantMiddlewareToken(TokenClaims::TYPE_TENANT, $userId, $tenantA);
    $spy = tenantProbeSpy();

    // The discriminating input: every request-supplied channel names B, and
    // one of them names a database outright. Only the token says A.
    postJson(
        TENANT_PROBE_URI.'?tenant='.$tenantB.'&database='.urlencode(tenancyFixtureDatabasePath('tenant-b')),
        [
            'tenant' => $tenantB,
            'tenant_id' => $tenantB,
            'database' => tenancyFixtureDatabasePath('tenant-b'),
        ],
        [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $tenantB,
            'X-Tenant-Database' => tenancyFixtureDatabasePath('tenant-b'),
        ]
    )->assertOk();

    expect($spy->calls)->toBe(1)
        ->and($spy->resolvedTenantId)->toBe($tenantA)
        // The strong half: the row came out of A's file. A middleware that
        // had read any of the five values above would have read `tenant-b`.
        ->and($spy->markerRead)->toBe('tenant-a');

    // Control: the same request with none of those values still resolves A,
    // so the assertions above are about which input was used and not about a
    // request that happened to fail.
    postJson(TENANT_PROBE_URI, [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect($spy->calls)->toBe(2)
        ->and($spy->resolvedTenantId)->toBe($tenantA)
        ->and($spy->markerRead)->toBe('tenant-a');
});

it('leaves no tenant resolved after the request, whichever way it ended', function () {
    // The `finally` half of the middleware: a request must not hand the next
    // one a tenant. Asserted for both outcomes, because an early refusal
    // returns before enter() and a success returns after it.
    $userId = authFixtureUser('owner@example.com');
    $tenantA = authFixtureTenant('shop-a');

    authFixtureMembership($tenantA, $userId);
    tenantMiddlewareMapping([$tenantA => 'tenant-a']);

    $token = tenantMiddlewareToken(TokenClaims::TYPE_TENANT, $userId, $tenantA);
    $spy = tenantProbeSpy();

    postJson(TENANT_PROBE_URI, [], ['Authorization' => 'Bearer '.$token])->assertOk();

    expect($spy->calls)->toBe(1)
        ->and(app(Tenancy::class)->current()->hasTenant())->toBeFalse()
        ->and(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());

    postJson(TENANT_PROBE_URI)->assertUnauthorized();

    expect($spy->calls)->toBe(1)
        ->and(app(Tenancy::class)->current()->hasTenant())->toBeFalse()
        ->and(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());
});

it('refuses a platform token, a missing token and an expired token, and never runs the handler', function () {
    // AC-46, with its liveness control in the same test: the spy that must
    // count zero three times is the same spy that must count exactly one at
    // the end. Without that last case the three zeros would also be what an
    // unwired spy reports.
    $userId = authFixtureUser('owner@example.com');
    $tenantA = authFixtureTenant('shop-a');

    authFixtureMembership($tenantA, $userId);
    tenantMiddlewareMapping([$tenantA => 'tenant-a']);

    $platformToken = tenantMiddlewareToken(TokenClaims::TYPE_PLATFORM, $userId);
    $tenantToken = tenantMiddlewareToken(TokenClaims::TYPE_TENANT, $userId, $tenantA);
    $spy = tenantProbeSpy();

    // (a) A platform token on a tenant-scoped route: 403, because the caller
    // holds a token of ours and presented the wrong kind.
    postJson(TENANT_PROBE_URI, [], ['Authorization' => 'Bearer '.$platformToken])
        ->assertForbidden()
        ->assertExactJson(['error' => AuthRefusals::WRONG_TOKEN_TYPE]);

    expect($spy->calls)->toBe(0);

    // (b) No token at all: 401, the generic refusal, byte for byte.
    $response = postJson(TENANT_PROBE_URI)->assertUnauthorized();

    expect($response->getContent())->toBe(GenericRefusal::json())
        ->and($spy->calls)->toBe(0);

    // (c) An expired tenant token: 401 and the same body. The token was
    // valid when it was minted; only the clock moved.
    authFixtureClockAt('2026-10-16 09:45:00.000');

    $response = postJson(TENANT_PROBE_URI, [], ['Authorization' => 'Bearer '.$tenantToken])
        ->assertUnauthorized();

    expect($response->getContent())->toBe(GenericRefusal::json())
        ->and($spy->calls)->toBe(0);

    // (d) The liveness control: the same spy, the same route, a token the
    // middleware must accept. Exactly once.
    authFixtureClockAt(TENANT_MIDDLEWARE_CLOCK);

    postJson(TENANT_PROBE_URI, [], ['Authorization' => 'Bearer '.$tenantToken])
        ->assertOk();

    expect($spy->calls)->toBe(1)
        ->and($spy->resolvedTenantId)->toBe($tenantA);
});

it('refuses a tenant token whose tenant has no mapping, with the kernel deciding', function () {
    // The gate is the kernel's and it runs because the middleware entered
    // the tenant. A shop with no mapping row cannot be opened, and the
    // handler does not run - which is the same shape step 13 uses for a
    // `suspended` mapping, reached here without a fixture status.
    $userId = authFixtureUser('owner@example.com');
    $tenantA = authFixtureTenant('shop-a');
    $unmapped = authFixtureTenant('shop-unmapped');

    authFixtureMembership($unmapped, $userId);
    tenantMiddlewareMapping([$tenantA => 'tenant-a']);

    $token = tenantMiddlewareToken(TokenClaims::TYPE_TENANT, $userId, $unmapped);
    $spy = tenantProbeSpy();

    postJson(TENANT_PROBE_URI, [], ['Authorization' => 'Bearer '.$token])
        ->assertStatus(500);

    expect($spy->calls)->toBe(0);

    // Liveness again: the mapped tenant on the same route runs the handler.
    $mapped = tenantMiddlewareToken(TokenClaims::TYPE_TENANT, $userId, $tenantA);

    postJson(TENANT_PROBE_URI, [], ['Authorization' => 'Bearer '.$mapped])
        ->assertOk();

    expect($spy->calls)->toBe(1);
});

it('puts the verified subject and tenant on the request and reads neither from it', function () {
    // The two attributes a module handler will read. They are set from the
    // claims and from nothing else, so a request that pre-sets them cannot
    // choose its own subject.
    $userId = authFixtureUser('owner@example.com');
    $tenantA = authFixtureTenant('shop-a');

    authFixtureMembership($tenantA, $userId);
    tenantMiddlewareMapping([$tenantA => 'tenant-a']);

    $token = tenantMiddlewareToken(TokenClaims::TYPE_TENANT, $userId, $tenantA);
    $seen = [];

    Route::middleware(RequireTenantToken::class)->post('/t9/tenant-attributes', function (Request $request) use (&$seen) {
        $seen = [
            'user' => $request->attributes->get(RequireTenantToken::USER_ID),
            'tenant' => $request->attributes->get(RequireTenantToken::TENANT_ID),
            'claims' => $request->attributes->get(RequireTenantToken::CLAIMS),
        ];

        return response()->json(['ok' => true]);
    });

    postJson('/t9/tenant-attributes', [RequirePlatformToken::USER_ID => 'somebody-else'], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    expect($seen['user'])->toBe($userId)
        ->and($seen['tenant'])->toBe($tenantA)
        ->and(TokenClaims::namesOf($seen['claims']))->toBe(TokenClaims::allowedNames(TokenClaims::TYPE_TENANT));
});

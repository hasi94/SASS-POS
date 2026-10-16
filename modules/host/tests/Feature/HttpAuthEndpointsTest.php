<?php

use App\Http\Requests\AuthRequestContext;
use App\Http\Responses\AuthRefusals;
use Flexipos\Auth\Exceptions\NoTenantAccess;
use Flexipos\Auth\Exceptions\TenantNotServable;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Support\GenericRefusal;
use Flexipos\Auth\Tokens\SigningKeyring;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| T-009 step 12: the four endpoints
|--------------------------------------------------------------------------
|
| AC-42 and AC-48 directly; AC-6, AC-9, AC-31, AC-40, AC-41 and AC-49 at the
| wire, where each one's rule has already been proved inside its slice. What
| is new here is only what the HTTP layer adds: a status code, a body, and
| the order the two refusals of a route happen in.
|
| **The byte-identity of the refusal is asserted as bytes, across
| endpoints.** D-12 says one status, one body; step 9 made that structural
| by giving `GenericRefusal` no argument through which a cause could reach
| it, and step 12 is the first time there are six callers that could have
| disagreed. So the comparison is `$response->getContent()` against
| `GenericRefusal::json()` - the producer's own encoding - and the same
| string is required from login, refresh and the middleware. A test that
| compared two responses to each other would pass just as well if both were
| wrong together.
|
| **Every absence here is paired with its presence.** No `login_history`
| row on a 422 and on a 503 is asserted beside the same request writing one
| when it is well-formed and a key is active, because an empty table is also
| what a broken writer, a failed migration or a test that never issued the
| request reports (plan section 7, shape 5).
|
| The master is the suite's in-memory SQLite stand-in. No engine.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';

const HTTP_AUTH_CLOCK = '2026-10-16 10:00:00.000';

const HTTP_AUTH_KID = 'http-auth-key';

const HTTP_AUTH_PASSWORD = 'correct-horse-battery-staple';

/**
 * Every `login_history` row, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function httpAuthHistory(): array
{
    return array_map(
        static fn (object $row): array => (array) $row,
        DB::connection('master')->table('login_history')->orderBy('id')->get()->all()
    );
}

/**
 * Log in over HTTP and return the decoded body.
 *
 * @return array<string, mixed>
 */
function httpAuthLogin(string $email = 'owner@example.com', string $password = HTTP_AUTH_PASSWORD): array
{
    $response = postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);

    $response->assertOk();

    return (array) $response->json();
}

beforeEach(function () {
    authFixtureClockAt(HTTP_AUTH_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(HTTP_AUTH_KID);
});

it('mounts the four endpoints and nothing else under api/v1/auth', function () {
    $mounted = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/auth'))
        ->map(fn ($route): string => implode(' ', $route->methods()).' '.$route->uri())
        ->sort()
        ->values()
        ->all();

    expect($mounted)->toBe([
        'POST api/v1/auth/login',
        'POST api/v1/auth/logout',
        'POST api/v1/auth/refresh',
        'POST api/v1/auth/tenant',
    ]);
});

it('answers a login with the token pair, the tenant list and the default', function () {
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, $userId, ['is_default' => true]);

    $body = httpAuthLogin();

    expect(array_keys($body))->toBe([
        'access_token',
        'refresh_token',
        'tenants',
        'default_tenant_id',
        'must_change_password',
    ])
        ->and($body['default_tenant_id'])->toBe($tenantId)
        ->and($body['must_change_password'])->toBeFalse()
        ->and($body['tenants'])->toHaveCount(1)
        ->and($body['tenants'][0]['code'])->toBe('shop-a')
        ->and($body['tenants'][0]['selectable'])->toBeTrue();

    // The attempt was recorded, which is the presence the absences below are
    // measured against.
    $history = httpAuthHistory();

    expect($history)->toHaveCount(1)
        ->and((bool) $history[0]['success'])->toBeTrue()
        ->and($history[0]['email'])->toBe('owner@example.com')
        ->and($history[0]['failure_reason'])->toBeNull();
});

it('answers a wrong password and an unknown email with the same bytes, and the right one with 200', function () {
    // AC-6, at the wire. The positive control is in the same test, so the
    // comparison is between a refusal and a success and not between two
    // refusals of nothing.
    authFixtureUser('owner@example.com');

    $wrongPassword = postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => 'not-the-password',
    ])->assertUnauthorized();

    $unknownEmail = postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => HTTP_AUTH_PASSWORD,
    ])->assertUnauthorized();

    expect($wrongPassword->getContent())->toBe(GenericRefusal::json())
        ->and($unknownEmail->getContent())->toBe(GenericRefusal::json())
        ->and($wrongPassword->headers->get('content-type'))->toBe($unknownEmail->headers->get('content-type'));

    // The control.
    postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => HTTP_AUTH_PASSWORD,
    ])->assertOk();

    // And the cause survives where it is supposed to: two refusals that look
    // identical outside are told apart inside.
    expect(array_column(httpAuthHistory(), 'failure_reason'))->toBe([
        RecordLoginAttempt::REASON_BAD_PASSWORD,
        RecordLoginAttempt::REASON_UNKNOWN_USER,
        null,
    ]);
});

it('answers an unreadable login with 422 and writes no history row', function () {
    // AC-9 at the wire, with its own control: the boundary lengths are
    // accepted as input and fail as credentials, so 422 is a statement about
    // the shape of the request and not about long values.
    authFixtureUser('owner@example.com');

    postJson('/api/v1/auth/login', ['password' => HTTP_AUTH_PASSWORD])
        ->assertStatus(422)
        ->assertExactJson(['error' => AuthRefusals::INVALID_REQUEST, 'reason' => 'email_missing']);

    postJson('/api/v1/auth/login', ['email' => ['an', 'array'], 'password' => HTTP_AUTH_PASSWORD])
        ->assertStatus(422)
        ->assertExactJson(['error' => AuthRefusals::INVALID_REQUEST, 'reason' => 'email_not_string']);

    postJson('/api/v1/auth/login', [
        'email' => str_repeat('a', 180).'@example.com',
        'password' => HTTP_AUTH_PASSWORD,
    ])->assertStatus(422)->assertExactJson([
        'error' => AuthRefusals::INVALID_REQUEST,
        'reason' => 'email_too_long',
    ]);

    expect(httpAuthHistory())->toBe([]);

    // Control, both halves: an email of exactly 191 characters and a
    // password of exactly 1024 are read, judged and refused as credentials -
    // a 401, and a row.
    $atTheLimit = str_repeat('a', 191 - strlen('@example.com')).'@example.com';

    expect(strlen($atTheLimit))->toBe(191);

    postJson('/api/v1/auth/login', [
        'email' => $atTheLimit,
        'password' => str_repeat('p', 1024),
    ])->assertUnauthorized();

    expect(httpAuthHistory())->toHaveCount(1)
        ->and(httpAuthHistory()[0]['failure_reason'])->toBe(RecordLoginAttempt::REASON_UNKNOWN_USER);
});

it('answers a login with no active signing key with 503 and writes no history row', function () {
    // AC-31 at the wire. The keyring has logged it at error level; the
    // client gets a 5xx-class refusal that names no mechanism.
    $userId = authFixtureUser('owner@example.com');
    authFixtureSetKeyStatus(HTTP_AUTH_KID, SigningKeyring::STATUS_RETIRED);

    postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => HTTP_AUTH_PASSWORD,
    ])->assertStatus(503)->assertExactJson(['error' => AuthRefusals::SIGNING_UNAVAILABLE]);

    expect(httpAuthHistory())->toBe([])
        ->and(DB::connection('master')->table('refresh_tokens')->count())->toBe(0);

    // The liveness half: the same request, with a key active, produces all
    // three presences.
    authFixtureSetKeyStatus(HTTP_AUTH_KID, SigningKeyring::STATUS_ACTIVE);

    postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => HTTP_AUTH_PASSWORD,
    ])->assertOk();

    expect(httpAuthHistory())->toHaveCount(1)
        ->and(DB::connection('master')->table('refresh_tokens')->count())->toBe(1)
        ->and(authFixtureUserRow($userId)['last_login_at'])->not->toBeNull();
});

it('records the address and the device the request arrived with', function () {
    // The three fields the HTTP layer contributes, and the one cut it makes.
    authFixtureUser('owner@example.com');

    postJson(
        '/api/v1/auth/login',
        ['email' => 'owner@example.com', 'password' => HTTP_AUTH_PASSWORD],
        [
            'User-Agent' => 'FlexiPOS-Till/1.0',
            AuthRequestContext::DEVICE_HEADER => str_repeat('d', AuthRequestContext::DEVICE_ID_LIMIT + 40),
        ]
    )->assertOk();

    $row = httpAuthHistory()[0];

    expect($row['user_agent'])->toBe('FlexiPOS-Till/1.0')
        ->and(strlen((string) $row['device_id']))->toBe(AuthRequestContext::DEVICE_ID_LIMIT)
        // AC-27's shape: the address is read back as a printed address, never
        // as the packed bytes' length in SQL.
        ->and($row['ip'])->not->toBeNull();
});

it('refuses tenant selection without a platform token, and with a tenant one', function () {
    // AC-42: 401 for missing, 403 for a token of the wrong type, and the
    // valid platform token in the same test as the control.
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, $userId);

    $login = httpAuthLogin();

    $missing = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'])->assertUnauthorized();

    expect($missing->getContent())->toBe(GenericRefusal::json());

    postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], ['Authorization' => 'Bearer not-a-token'])
        ->assertUnauthorized();

    // The control: the platform token from the login above is accepted, and
    // the tenant token it returns is then refused by the same endpoint -
    // which is AC-42's "a tenant access token" case.
    $selected = postJson(
        '/api/v1/auth/tenant',
        ['code' => 'shop-a'],
        ['Authorization' => 'Bearer '.$login['access_token']]
    )->assertOk()->json();

    expect($selected['tenant_id'])->toBe($tenantId);

    postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], ['Authorization' => 'Bearer '.$selected['access_token']])
        ->assertForbidden()
        ->assertExactJson(['error' => AuthRefusals::WRONG_TOKEN_TYPE]);
});

it('refuses an unreadable tenant code with 422 and an unknown one with 403', function () {
    // AC-41 and AC-40(a), at the wire and in one test so the two statuses
    // are visibly different answers to different questions.
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, $userId);

    $login = httpAuthLogin();
    $bearer = ['Authorization' => 'Bearer '.$login['access_token']];

    postJson('/api/v1/auth/tenant', ['code' => '   '], $bearer)
        ->assertStatus(422)
        ->assertExactJson(['error' => AuthRefusals::INVALID_REQUEST, 'reason' => 'code_missing']);

    postJson('/api/v1/auth/tenant', ['code' => 'no-such-shop'], $bearer)
        ->assertForbidden()
        ->assertExactJson(['error' => NoTenantAccess::ERROR]);

    // AC-40(b): the caller's own shop, refused with its status.
    $closed = authFixtureTenant('shop-closed', 'closed');

    authFixtureMembership($closed, $userId);

    postJson('/api/v1/auth/tenant', ['code' => 'shop-closed'], $bearer)
        ->assertForbidden()
        ->assertExactJson(['error' => TenantNotServable::ERROR, 'status' => 'closed']);
});

it('rotates a refresh token and refuses the one it replaced', function () {
    authFixtureUser('owner@example.com');

    $login = httpAuthLogin();

    $rotated = postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])
        ->assertOk()
        ->json();

    expect(array_keys($rotated))->toBe(['access_token', 'refresh_token'])
        ->and($rotated['refresh_token'])->not->toBe($login['refresh_token']);

    // The replayed value is the same generic refusal, byte for byte, as a
    // wrong password - which is the whole of D-12 at the HTTP surface.
    $replayed = postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])
        ->assertUnauthorized();

    expect($replayed->getContent())->toBe(GenericRefusal::json());

    // A missing value is the same answer again, and no 422 anywhere.
    $missing = postJson('/api/v1/auth/refresh', [])->assertUnauthorized();

    expect($missing->getContent())->toBe(GenericRefusal::json());
});

it('answers every logout the same way and revokes the family of a real one', function () {
    // AC-49. The two responses must be identical bytes, and the difference
    // between them must be visible only in the database.
    authFixtureUser('owner@example.com');

    $login = httpAuthLogin();

    $unknown = postJson('/api/v1/auth/logout', ['refresh_token' => 'not-a-token-of-ours'])->assertOk();

    expect(DB::connection('master')->table('refresh_tokens')->whereNotNull('revoked_at')->count())->toBe(0);

    $real = postJson('/api/v1/auth/logout', ['refresh_token' => $login['refresh_token']])->assertOk();

    expect($real->getContent())->toBe($unknown->getContent())
        ->and(DB::connection('master')->table('refresh_tokens')->whereNotNull('revoked_at')->count())->toBe(1);

    // And the revoked token no longer refreshes.
    postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])
        ->assertUnauthorized();
});

it('signs a lost device out with every signing key retired', function () {
    // The reason logout resolves no key, asserted rather than asserted in a
    // comment: revocation is the one operation that must survive the failure
    // mode in which an operator most needs it. The control is in the same
    // test - login, which does resolve a key, answers 503 under exactly the
    // same conditions.
    authFixtureUser('owner@example.com');

    $login = httpAuthLogin();

    authFixtureSetKeyStatus(HTTP_AUTH_KID, SigningKeyring::STATUS_RETIRED);

    postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => HTTP_AUTH_PASSWORD,
    ])->assertStatus(503);

    postJson('/api/v1/auth/logout', ['refresh_token' => $login['refresh_token']])->assertOk();

    expect(DB::connection('master')->table('refresh_tokens')->whereNotNull('revoked_at')->count())->toBe(1);
});

it('keeps an access token valid until exp after the account is disabled', function () {
    // AC-48, stated as accepted behaviour so that QA does not file it. The
    // window is the token's and nothing shortens it; the control is that the
    // same account cannot log in again.
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, $userId);

    $login = httpAuthLogin();

    DB::connection('master')->table('users')->where('id', $userId)->update(['status' => 'disabled']);

    postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], ['Authorization' => 'Bearer '.$login['access_token']])
        ->assertOk();

    postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => HTTP_AUTH_PASSWORD,
    ])->assertUnauthorized();
});

<?php

use App\Http\Responses\AuthRefusals;
use Flexipos\Auth\Exceptions\NoTenantAccess;
use Flexipos\Auth\Features\Login\LoginHandler;
use Flexipos\Auth\Features\RecordLoginAttempt\RecordLoginAttempt;
use Flexipos\Auth\Support\GenericRefusal;
use Flexipos\Auth\Tokens\SigningKeyring;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withServerVariables;

/*
|--------------------------------------------------------------------------
| T-009 step 13/14: what the HTTP surface does that no slice test can see
|--------------------------------------------------------------------------
|
| Five things dev listed as weak, each turned into a measurement:
|
|  - AC-27 at the wire, both address families, asserted on the PHP value after
|    `inet_ntop` and never on `length(ip)` or `typeof(ip)` in SQL (SQLite
|    truncates both at the first NUL and reports 1 and text).
|  - the proxy hazard FP-297 files: a forwarded address is NOT what lands in the
|    column today. Pinned as it is, so fixing FP-297 changes this test on purpose.
|  - the expiry boundary through the middleware, not only inside the verifier.
|  - the exception map: the six catch blocks map exception types to responses,
|    and nothing used to say the map covers every type the package defines.
|  - AC-23's five outcomes through the real endpoint, with the no-key control.
|
| No engine; the master is the suite's in-memory SQLite stand-in.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';

const QA_SURFACE_CLOCK = '2026-10-16 13:00:00.000';

const QA_SURFACE_KID = 'qa-surface-key';

const QA_SURFACE_PASSWORD = 'correct-horse-battery-staple';

/**
 * @return list<array<string, mixed>>
 */
function qaSurfaceHistory(): array
{
    return array_map(
        static fn (object $row): array => (array) $row,
        DB::connection('master')->table('login_history')->orderBy('id')->get()->all()
    );
}

/**
 * A stored packed address, read back as the address it prints as.
 *
 * `inet_ntop` on the PHP value, because that is the only place the value is
 * trustworthy on the stand-in (see the header).
 */
function qaSurfacePrinted(mixed $stored): ?string
{
    if ($stored === null) {
        return null;
    }

    $printed = @inet_ntop((string) $stored);

    return $printed === false ? 'NOT-A-PACKED-ADDRESS' : $printed;
}

beforeEach(function () {
    authFixtureClockAt(QA_SURFACE_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(QA_SURFACE_KID);
});

it('round-trips a v4 and a v6 caller through login_history, users and refresh_tokens (AC-27)', function () {
    authFixtureUser('v4@example.com');
    authFixtureUser('v6@example.com');

    $callers = ['v4@example.com' => '203.0.113.7', 'v6@example.com' => '2001:db8::1'];

    foreach ($callers as $email => $address) {
        withServerVariables(['REMOTE_ADDR' => $address]);

        postJson('/api/v1/auth/login', ['email' => $email, 'password' => QA_SURFACE_PASSWORD])->assertOk();
    }

    foreach ($callers as $email => $address) {
        $user = (array) DB::connection('master')->table('users')->where('email', $email)->first();
        $history = (array) DB::connection('master')->table('login_history')->where('email', $email)->first();
        $refresh = (array) DB::connection('master')->table('refresh_tokens')->where('principal_id', $user['id'])->first();

        expect(qaSurfacePrinted($history['ip']))->toBe($address)
            ->and(qaSurfacePrinted($user['last_login_ip']))->toBe($address)
            ->and(qaSurfacePrinted($refresh['ip']))->toBe($address);
    }

    // The control: the two callers did not collapse into one value, and the
    // two families are stored in the same column at their own widths.
    $stored = DB::connection('master')->table('login_history')->orderBy('id')->pluck('ip')->all();

    expect(count($stored))->toBe(2)
        ->and($stored[0])->not->toBe($stored[1])
        ->and(strlen((string) $stored[0]))->toBe(4)
        ->and(strlen((string) $stored[1]))->toBe(16);
});

it('records the connecting address, not a forwarded one, behind a proxy - pinned until FP-297 lands', function () {
    // dev's weakness 1, measured. TrustProxies is unconfigured, so a request
    // that arrives from the reverse proxy carries the proxy's address in
    // REMOTE_ADDR and the client's in X-Forwarded-For, and the column gets the
    // proxy. That is the SAFE default (an unconfigured app must not believe a
    // client-supplied header) and the deployment gap FP-297 files.
    //
    // THIS TEST IS EXPECTED TO CHANGE when FP-297 configures trusted proxies:
    // the forwarded address becomes the recorded one. Failing here after that
    // change means update this test, not revert the change.
    authFixtureUser('owner@example.com');

    withServerVariables(['REMOTE_ADDR' => '10.0.0.5']);

    postJson(
        '/api/v1/auth/login',
        ['email' => 'owner@example.com', 'password' => QA_SURFACE_PASSWORD],
        ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https']
    )->assertOk();

    $viaProxy = qaSurfaceHistory()[0];

    expect(qaSurfacePrinted($viaProxy['ip']))->toBe('10.0.0.5');

    // The control: with no forwarding header the connecting address is
    // recorded - so the line above is the header being ignored, not the
    // address being lost.
    withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

    postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SURFACE_PASSWORD])->assertOk();

    expect(qaSurfacePrinted(qaSurfaceHistory()[1]['ip']))->toBe('203.0.113.9');
});

it('accepts a platform token one second before exp and refuses it at exactly exp, through the middleware', function () {
    // dev's weakness 5: the boundary was proved inside the verifier and only
    // "well past" at the HTTP layer. Same token, three clocks; the token's exp
    // is its iat plus 15 minutes.
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, $userId);

    $token = (string) postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SURFACE_PASSWORD])
        ->assertOk()
        ->json('access_token');

    $bearer = ['Authorization' => 'Bearer '.$token];

    authFixtureClockAt('2026-10-16 13:14:59.000');

    postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer)->assertOk();

    authFixtureClockAt('2026-10-16 13:15:00.000');

    $atExp = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer)->assertUnauthorized();

    expect($atExp->getContent())->toBe(GenericRefusal::json());

    authFixtureClockAt('2026-10-16 13:15:01.000');

    postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer)->assertUnauthorized();
});

it('maps every exception type the package defines to a non-500 response, and sees an unmapped one as a 500', function () {
    // dev's weakness 7. The matrix is driven through the real endpoints with
    // inputs that make each type surface; the directory listing is the
    // exhaustiveness check, so a seventh exception class that nobody mapped
    // and nobody listed here fails by name.
    //
    // LIMIT, stated: this catches a new exception TYPE. A new THROW SITE of an
    // existing type on an endpoint whose controller does not catch it still
    // surfaces as a 500, and nothing here would notice. That is the residual.
    // --- the control, FIRST: the same assertion DOES see a 500 ---------------
    // Make the login path throw a type its controller does not catch. If this
    // answered anything but 500 the matrix below could not tell a missing
    // mapping from a present one. It runs before any other login request on
    // purpose: the router caches a route's controller instance after its first
    // use, so a binding made later would never be consulted (measured: the
    // first version of this control answered 200 for exactly that reason).
    app()->bind(LoginHandler::class, static fn () => throw NoTenantAccess::refused());

    expect(postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SURFACE_PASSWORD])->getStatusCode())->toBe(500);

    app()->offsetUnset(LoginHandler::class);

    $userId = authFixtureUser('owner@example.com');
    $shop = authFixtureTenant('shop-a');
    $closed = authFixtureTenant('shop-closed', 'closed');

    authFixtureMembership($shop, $userId);
    authFixtureMembership($closed, $userId);

    $login = postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SURFACE_PASSWORD])->assertOk()->json();
    $bearer = ['Authorization' => 'Bearer '.$login['access_token']];

    $observed = [];

    $drive = function (string $exception, string $uri, array $body, array $headers, int $status) use (&$observed): void {
        $response = postJson($uri, $body, $headers);

        $observed[$exception][] = $uri.' -> '.$response->getStatusCode();

        expect($response->getStatusCode())->toBe($status, $exception.' via '.$uri.' answered '.$response->getStatusCode())
            ->and($response->getStatusCode())->not->toBe(500);
    };

    $drive('CredentialRefused', '/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'wrong'], [], 401);
    $drive('CredentialRefused', '/api/v1/auth/refresh', ['refresh_token' => 'never-issued'], [], 401);
    $drive('InvalidLoginInput', '/api/v1/auth/login', [], [], 422);
    $drive('InvalidTenantCode', '/api/v1/auth/tenant', ['code' => ''], $bearer, 422);
    $drive('NoTenantAccess', '/api/v1/auth/tenant', ['code' => 'no-such-shop'], $bearer, 403);
    $drive('TenantNotServable', '/api/v1/auth/tenant', ['code' => 'shop-closed'], $bearer, 403);
    $drive('AccessTokenRefused', '/api/v1/auth/tenant', ['code' => 'shop-a'], ['Authorization' => 'Bearer not-a-token'], 401);

    // The refresh-side business refusals, on a tenant-scoped token.
    $selected = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer)->assertOk()->json();

    DB::connection('master')->table('tenants')->where('id', $shop)->update(['status' => 'closed']);
    $drive('TenantNotServable', '/api/v1/auth/refresh', ['refresh_token' => $selected['refresh_token']], [], 403);

    DB::connection('master')->table('tenants')->where('id', $shop)->update(['status' => 'active']);
    DB::connection('master')->table('tenant_users')->where('tenant_id', $shop)->update(['status' => 'suspended']);
    $drive('NoTenantAccess', '/api/v1/auth/refresh', ['refresh_token' => $selected['refresh_token']], [], 403);

    DB::connection('master')->table('tenant_users')->where('tenant_id', $shop)->update(['status' => 'active']);

    // The operational failure in all three issuing slices.
    authFixtureSetKeyStatus(QA_SURFACE_KID, SigningKeyring::STATUS_RETIRING);
    $drive('NoActiveSigningKey', '/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SURFACE_PASSWORD], [], 503);
    $drive('NoActiveSigningKey', '/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer, 503);
    $drive('NoActiveSigningKey', '/api/v1/auth/refresh', ['refresh_token' => $selected['refresh_token']], [], 503);

    // --- exhaustiveness: the directory, not a list typed here ---------------
    $defined = array_map(
        static fn (string $file): string => basename($file, '.php'),
        glob(base_path('../../packages/auth/src/Exceptions/*.php')) ?: []
    );
    sort($defined);

    $driven = array_keys($observed);
    sort($driven);

    expect(count($defined))->toBeGreaterThanOrEqual(7)
        ->and($driven)->toBe($defined, 'an exception class exists that this matrix never drove through an endpoint');
});

it('writes exactly one login_history row per evaluated attempt for each of the five outcomes, and none without a key (AC-23)', function () {
    $active = authFixtureUser('active@example.com');
    $victim = authFixtureUser('victim@example.com');
    $disabled = authFixtureUser('disabled@example.com', QA_SURFACE_PASSWORD, ['status' => 'disabled']);

    $outcomes = [];
    $login = static fn (string $email, string $password): int => postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->getStatusCode();

    $step = function (string $expectedReason, int $status, string $email, string $password) use (&$outcomes, $login): void {
        $before = count(qaSurfaceHistory());

        expect($login($email, $password))->toBe($status);

        $rows = qaSurfaceHistory();

        // Exactly one row, and it is the one this request wrote.
        expect(count($rows))->toBe($before + 1);

        $row = $rows[$before];

        $outcomes[$expectedReason] = [
            'reason' => $row['failure_reason'] ?? 'SUCCESS',
            'success' => (bool) $row['success'],
            'principal_type' => $row['principal_type'],
            'tenant_id' => $row['tenant_id'],
            'user_matched' => $row['user_id'] !== null,
            'email' => $row['email'],
        ];
    };

    $step('SUCCESS', 200, 'active@example.com', QA_SURFACE_PASSWORD);
    $step('unknown_user', 401, 'nobody@example.com', QA_SURFACE_PASSWORD);
    $step('bad_password', 401, 'active@example.com', 'wrong');
    $step('not_active', 401, 'disabled@example.com', QA_SURFACE_PASSWORD);

    // locked: five failures to lock, then the CORRECT password during the lock.
    for ($i = 0; $i < 5; $i++) {
        $login('victim@example.com', 'wrong');
    }

    $step('locked', 401, 'victim@example.com', QA_SURFACE_PASSWORD);

    expect(array_keys($outcomes))->toBe(['SUCCESS', 'unknown_user', 'bad_password', 'not_active', 'locked']);

    foreach ($outcomes as $expected => $got) {
        expect($got['reason'])->toBe($expected)
            ->and($got['success'])->toBe($expected === 'SUCCESS')
            ->and($got['principal_type'])->toBe('user')
            ->and($got['tenant_id'])->toBeNull()
            ->and($got['user_matched'])->toBe($expected !== 'unknown_user');
    }

    unset($active, $victim, $disabled);

    // No other value is writable through the application: a mistyped reason is
    // refused at construction. A sixth word for a login is not asked for.
    expect(fn () => RecordLoginAttempt::failed('active@example.com', 'key_unavailable'))
        ->toThrow(InvalidArgumentException::class);

    // --- AC-23(2): no key, zero rows, with the positive control in the same test
    $before = count(qaSurfaceHistory());

    authFixtureSetKeyStatus(QA_SURFACE_KID, SigningKeyring::STATUS_RETIRED);

    expect($login('active@example.com', QA_SURFACE_PASSWORD))->toBe(503)
        ->and(count(qaSurfaceHistory()))->toBe($before)
        // The unknown address with no key writes nothing either: nothing was judged.
        ->and($login('nobody@example.com', QA_SURFACE_PASSWORD))->toBe(503)
        ->and(count(qaSurfaceHistory()))->toBe($before);

    authFixtureSetKeyStatus(QA_SURFACE_KID, SigningKeyring::STATUS_ACTIVE);

    expect($login('active@example.com', QA_SURFACE_PASSWORD))->toBe(200)
        ->and(count(qaSurfaceHistory()))->toBe($before + 1);

    // The refusal body is the generic one for every credential cause above
    // (D-12) and the cause is only in the history, which the loop checked.
    expect(GenericRefusal::json())->toBe(postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->getContent())
        ->and(AuthRefusals::credentials()->getContent())->toBe(GenericRefusal::json());
});

it('gives platform staff no way into a shop they are not a member of, and the same refusal as anyone else (AC-15)', function () {
    // The second half of AC-15, which no slice test exercised: LoginTest proves
    // staff get an empty list, and nothing tried to SELECT. The discriminating
    // input is a staff member and an ordinary outsider asking for the same
    // active shop - the only difference between them is the flag.
    $staff = authFixtureUser('staff@example.com', QA_SURFACE_PASSWORD, ['is_platform_staff' => true]);
    authFixtureUser('outsider@example.com');
    $acme = authFixtureTenant('acme');

    $staffLogin = postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => QA_SURFACE_PASSWORD])->assertOk()->json();
    $outsiderLogin = postJson('/api/v1/auth/login', ['email' => 'outsider@example.com', 'password' => QA_SURFACE_PASSWORD])->assertOk()->json();

    expect($staffLogin['tenants'])->toBe([]);

    $refusedStaff = postJson('/api/v1/auth/tenant', ['code' => 'acme'], ['Authorization' => 'Bearer '.$staffLogin['access_token']])
        ->assertForbidden();
    $refusedOutsider = postJson('/api/v1/auth/tenant', ['code' => 'acme'], ['Authorization' => 'Bearer '.$outsiderLogin['access_token']])
        ->assertForbidden();

    expect($refusedStaff->getContent())->toBe($refusedOutsider->getContent())
        ->and($refusedStaff->json('error'))->toBe(NoTenantAccess::ERROR);

    // The cause survives where it should: both refusals are no_access rows
    // naming the shop, one per person.
    $rows = array_values(array_filter(qaSurfaceHistory(), static fn (array $row): bool => $row['failure_reason'] === 'no_access'));

    expect(count($rows))->toBe(2)
        ->and($rows[0]['user_id'])->toBe($staff)
        ->and($rows[0]['tenant_id'])->toBe($acme);

    // The control: the same staff member WITH a membership is let in, so the
    // refusal above was about the missing membership and not about staff.
    authFixtureMembership($acme, $staff);

    postJson('/api/v1/auth/tenant', ['code' => 'acme'], ['Authorization' => 'Bearer '.$staffLogin['access_token']])->assertOk();
});

/**
 * The claims of a JWT, read without verifying it - the test already knows the
 * token came from the endpoint under test.
 *
 * @return array<string, mixed>
 */
function qaSurfaceClaims(string $jwt): array
{
    $segments = explode('.', $jwt);
    $decoded = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true);

    return is_array($decoded) ? $decoded : [];
}

it('ignores a tenant, a database, a host or a scope named in the body of login, selection and refresh (AC-10, AC-38, AC-43)', function () {
    // Slice tests prove the commands have nowhere to carry these fields; the
    // controllers are the only place a request body becomes a command, so this
    // is the regression the slice tests cannot see. Each endpoint is called
    // twice with the same clock - once clean, once carrying the extras - and
    // the answers must agree.
    $userId = authFixtureUser('owner@example.com');
    $shopA = authFixtureTenant('shop-a');
    $shopB = authFixtureTenant('shop-b');

    authFixtureMembership($shopA, $userId);
    authFixtureMembership($shopB, $userId);

    $extras = [
        'tenant' => $shopB, 'tenant_id' => $shopB, 'database' => 'flexipos_tenant_b',
        'host' => 'evil.example', 'driver' => 'sqlite', 'scope' => 'tenant:'.$shopB,
    ];

    // --- AC-10: login
    $clean = postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SURFACE_PASSWORD])->assertOk()->json();
    $loaded = postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_SURFACE_PASSWORD] + $extras)->assertOk()->json();

    $withoutJti = static fn (string $jwt): array => array_diff_key(qaSurfaceClaims($jwt), ['jti' => 1]);

    expect(array_keys($loaded))->toBe(array_keys($clean))
        ->and($loaded['tenants'])->toBe($clean['tenants'])
        ->and($loaded['default_tenant_id'])->toBe($clean['default_tenant_id'])
        ->and($withoutJti($loaded['access_token']))->toBe($withoutJti($clean['access_token']));

    // --- AC-43: selection. Code A, body names B and a database.
    $bearer = ['Authorization' => 'Bearer '.$clean['access_token']];

    $selectedClean = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer)->assertOk()->json();
    $selectedLoaded = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'] + $extras, $bearer)->assertOk()->json();

    expect(qaSurfaceClaims($selectedLoaded['access_token'])['tenant'])->toBe($shopA)
        ->and($selectedLoaded['tenant_id'])->toBe($shopA)
        ->and($withoutJti($selectedLoaded['access_token']))->toBe($withoutJti($selectedClean['access_token']));

    // The extras really name a different, selectable shop: selecting B by
    // code gives B - so "the token says A" is the code winning, not B being
    // unavailable.
    $selectedB = postJson('/api/v1/auth/tenant', ['code' => 'shop-b'], $bearer)->assertOk()->json();

    expect(qaSurfaceClaims($selectedB['access_token'])['tenant'])->toBe($shopB);

    // --- AC-38: refresh. A tenant-scoped token stays on its own shop, and a
    // platform token stays platform, whatever the body asks for.
    $rotatedTenant = postJson('/api/v1/auth/refresh', ['refresh_token' => $selectedLoaded['refresh_token']] + $extras)->assertOk()->json();
    $rotatedPlatform = postJson('/api/v1/auth/refresh', ['refresh_token' => $loaded['refresh_token']] + $extras)->assertOk()->json();

    expect(qaSurfaceClaims($rotatedTenant['access_token'])['tenant'])->toBe($shopA)
        ->and(qaSurfaceClaims($rotatedTenant['access_token'])['token_type'])->toBe('tenant')
        ->and(array_key_exists('tenant', qaSurfaceClaims($rotatedPlatform['access_token'])))->toBeFalse()
        ->and(qaSurfaceClaims($rotatedPlatform['access_token'])['token_type'])->toBe('platform');
});

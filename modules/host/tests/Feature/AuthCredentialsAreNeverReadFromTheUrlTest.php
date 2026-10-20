<?php

use App\Http\Requests\AuthRequestContext;
use App\Http\Responses\AuthRefusals;
use Flexipos\Auth\Support\GenericRefusal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| R-1: the request URL is a sink, and no credential may reach it
|--------------------------------------------------------------------------
|
| **A fifth sink, and the reason this file exists rather than another case
| in the sentinel harness.** AC-26's harness enumerates four places a secret
| could be written - `login_history`, `security_events`, the application log
| and exception messages - and scans all four for sentinels. Every one of
| them is inside the application. The request URL is outside it: nginx sits
| in front (docs/deployment-vps.md) and logs the request URI by default, and
| nothing in this system prunes that file. No amount of scanning inside the
| four sinks can see a credential that was never written to any of them,
| which is why this is pinned by behaviour at the endpoints instead - by
| what each one does when the credential is **only** in the URL.
|
| **What is actually asserted.** `$request->input($key)` merges the query
| bag as a fallback (`data_get($this->getInputSource()->all() +
| $this->query->all(), ...)`), so an endpoint reading a credential through
| it accepts that credential from the URL. Every endpoint here is sent its
| credential in the query string with an empty JSON body, and must answer as
| though nothing was submitted: 422 `email_missing` from login, 422
| `code_missing` from selection, the generic 401 from refresh, and from
| logout - whose answer is always 200 by AC-49 - a database in which nothing
| was revoked.
|
| **Each refusal is paired with the same value succeeding in the body**, in
| the same test. That control is what separates "the URL was ignored" from
| "the value was wrong, the account does not exist, or the test issued no
| request at all" - four refusals of nothing would pass just as well.
|
| The first case is the mechanism itself, against a real `Request`: that
| `input()` does see the URL and `AuthRequestContext::body()` does not, with
| a JSON body and a form body both read as the control. If that case ever
| goes green for the wrong reason - the framework stops merging the query
| bag - the four endpoint cases would also pass with `input()` restored, so
| it is stated where a reader can see the premise the other four rest on.
|
| The master is the suite's in-memory SQLite stand-in. No engine.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';

const URL_SINK_CLOCK = '2026-10-20 09:00:00.000';

const URL_SINK_KID = 'url-sink-key';

const URL_SINK_PASSWORD = 'url-sink-correct-horse-battery';

/**
 * A real POST with a JSON body and whatever the URL carries.
 */
function urlSinkRequest(string $uri, string $json = '{}'): Request
{
    return Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
}

/**
 * How many refresh-token rows are revoked right now.
 */
function urlSinkRevokedCount(): int
{
    return DB::connection('master')->table('refresh_tokens')->whereNotNull('revoked_at')->count();
}

/**
 * Log in over HTTP, in the body, and return the decoded pair.
 *
 * @return array<string, mixed>
 */
function urlSinkLogin(): array
{
    $response = postJson('/api/v1/auth/login', [
        'email' => 'owner@example.com',
        'password' => URL_SINK_PASSWORD,
    ]);

    $response->assertOk();

    return (array) $response->json();
}

beforeEach(function () {
    authFixtureClockAt(URL_SINK_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(URL_SINK_KID);
});

it('reads the body and never the query string, where input() reads both', function () {
    // The mechanism, stated against the framework rather than described.
    $fromUrl = urlSinkRequest('/api/v1/auth/login?email=url@example.com&password=hunter2');

    expect($fromUrl->input('email'))->toBe('url@example.com')
        ->and($fromUrl->input('password'))->toBe('hunter2')
        ->and(AuthRequestContext::body($fromUrl, 'email'))->toBeNull()
        ->and(AuthRequestContext::body($fromUrl, 'password'))->toBeNull();

    // Control one: a JSON body, which is what every client sends and which
    // never populates the `request` bag - so `$request->post()` would have
    // been an empty read here and this case would pass for the wrong reason.
    $json = urlSinkRequest(
        '/api/v1/auth/login',
        (string) json_encode(['email' => 'body@example.com', 'password' => 'in-the-body'])
    );

    expect(AuthRequestContext::body($json, 'email'))->toBe('body@example.com')
        ->and(AuthRequestContext::body($json, 'password'))->toBe('in-the-body');

    // Control two: a form-encoded body is read as well, so the accessor is
    // body-only and not JSON-only.
    $form = Request::create('/api/v1/auth/login', 'POST', ['email' => 'form@example.com']);

    expect(AuthRequestContext::body($form, 'email'))->toBe('form@example.com');

    // And a URL parameter is invisible even when the body is read from.
    $both = urlSinkRequest(
        '/api/v1/auth/login?password=from-the-url',
        (string) json_encode(['email' => 'body@example.com'])
    );

    expect(AuthRequestContext::body($both, 'password'))->toBeNull()
        ->and(AuthRequestContext::body($both, 'email'))->toBe('body@example.com');
});

it('refuses a login whose credentials are only in the URL', function () {
    authFixtureUser('owner@example.com', URL_SINK_PASSWORD);

    $query = 'email=owner%40example.com&password='.rawurlencode(URL_SINK_PASSWORD);

    postJson('/api/v1/auth/login?'.$query, [])
        ->assertStatus(422)
        ->assertExactJson(['error' => AuthRefusals::INVALID_REQUEST, 'reason' => 'email_missing']);

    // Not merely refused: never judged. No row was written, so the password
    // in the URL did not reach the hasher either.
    expect(DB::connection('master')->table('login_history')->count())->toBe(0);

    // The control: the same two values, in the body, are a 200 and a row.
    urlSinkLogin();

    expect(DB::connection('master')->table('login_history')->count())->toBe(1);
});

it('refuses a refresh whose token is only in the URL', function () {
    authFixtureUser('owner@example.com', URL_SINK_PASSWORD);

    $login = urlSinkLogin();
    $token = (string) $login['refresh_token'];

    $refused = postJson('/api/v1/auth/refresh?refresh_token='.rawurlencode($token), [])
        ->assertUnauthorized();

    expect($refused->getContent())->toBe(GenericRefusal::json())
        // D-24's shape, which is also the evidence the token was not seen:
        // a token that had been read would have been consumed.
        ->and(DB::connection('master')->table('refresh_tokens')->whereNotNull('used_at')->count())->toBe(0);

    // The control: the same token, in the body, rotates.
    $rotated = postJson('/api/v1/auth/refresh', ['refresh_token' => $token])->assertOk()->json();

    expect($rotated['refresh_token'])->not->toBe($token)
        ->and(DB::connection('master')->table('refresh_tokens')->whereNotNull('used_at')->count())->toBe(1);
});

it('refuses a tenant selection whose code is only in the URL', function () {
    $userId = authFixtureUser('owner@example.com', URL_SINK_PASSWORD);
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, $userId);

    $login = urlSinkLogin();
    $bearer = ['Authorization' => 'Bearer '.$login['access_token']];

    postJson('/api/v1/auth/tenant?code=shop-a', [], $bearer)
        ->assertStatus(422)
        ->assertExactJson(['error' => AuthRefusals::INVALID_REQUEST, 'reason' => 'code_missing']);

    // The control: the same code, in the body, selects the shop. The bearer
    // token is identical in both requests, so the 422 above is about where
    // the code was and about nothing else.
    $selected = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], $bearer)->assertOk()->json();

    expect($selected['tenant_id'])->toBe($tenantId);
});

it('revokes nothing for a logout whose token is only in the URL', function () {
    // AC-49 gives logout one answer whatever it is sent, so the refusal is
    // not visible in the response; it is visible in the database.
    authFixtureUser('owner@example.com', URL_SINK_PASSWORD);

    $login = urlSinkLogin();
    $token = (string) $login['refresh_token'];

    postJson('/api/v1/auth/logout?refresh_token='.rawurlencode($token), [])->assertOk();

    expect(urlSinkRevokedCount())->toBe(0);

    // The control: the same token, in the body, revokes the family.
    postJson('/api/v1/auth/logout', ['refresh_token' => $token])->assertOk();

    expect(urlSinkRevokedCount())->toBe(1);
});

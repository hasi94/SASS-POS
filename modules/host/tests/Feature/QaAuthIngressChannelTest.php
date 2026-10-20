<?php

use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\post;

/*
|--------------------------------------------------------------------------
| T-009 R-1 re-test: the ingress channel the sentinel harness never exercises
|--------------------------------------------------------------------------
|
| QaSecretSinkCaptureTest scans four places a secret could be WRITTEN
| (login_history, security_events, the log, exception messages) and only ever
| POSTs a body. The request URL is not a place the application writes; it is
| a channel a credential can ARRIVE by, and AuthCredentialsAreNeverReadFromTheUrlTest
| pins each endpoint's refusal of it. Two things that file cannot say for
| itself, said here:
|
|  1. `AuthRequestContext::body()` replaced `input()` and its docblock claims a
|     form-encoded body still works. The sibling file proves that on a bare
|     Request and proves the endpoints on JSON; this proves a form-encoded
|     POST through the real kernel is read, and that a form POST with the
|     credential only in the URL is refused.
|  2. The pin is per endpoint, so a fifth endpoint added tomorrow is not
|     covered by it and nothing would notice. The set of `api/v1/auth` routes
|     is compared with the set of endpoints that sibling file sends a
|     query-string credential to. The extractor is shown to see a planted
|     route and a planted URL, so an empty result cannot pass for agreement.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';

const QA_INGRESS_PASSWORD = 'qa-ingress-correct-horse-battery';

/**
 * @return list<string> the URIs of every route under api/v1/auth, sorted
 */
function qaIngressAuthUris(RouteCollectionInterface $routes): array
{
    $uris = [];

    foreach ($routes->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/auth/')) {
            $uris[] = '/'.$route->uri();
        }
    }

    sort($uris);

    return $uris;
}

/**
 * R2-W3. Anchored to a call site, not to the bare URL. This reads the sibling
 * file's raw text, so an unanchored pattern counted a URL mentioned in a
 * comment as a pin: adding a route and naming its URL in a comment would make
 * the completeness check below agree with the router while no request was ever
 * sent. Requiring `post(` or `postJson(` immediately before the quote means
 * only a URL that is actually dispatched counts. `[a-z0-9_-]+` replaces
 * `[a-z-]+`, which could not see a digit or an underscore in a route segment.
 *
 * @return list<string> every /api/v1/auth/<x> that the source posts to with a query string, sorted
 */
function qaIngressUrlPinnedUris(string $source): array
{
    preg_match_all('#\b(?:postJson|post)\(\s*[\'"](/api/v1/auth/[a-z0-9_-]+)\?#', $source, $m);

    $uris = array_values(array_unique($m[1]));
    sort($uris);

    return $uris;
}

beforeEach(function () {
    authFixtureClockAt('2026-10-20 09:00:00.000');
    authFixtureMigrateMaster();
    authFixtureInstallKey('qa-ingress-key');
});

it('reads a form-encoded body through the real kernel, and refuses a form POST whose credentials are only in the URL', function () {
    authFixtureUser('owner@example.com', QA_INGRESS_PASSWORD);

    // Refused first: credentials in the URL, a form-encoded empty body.
    $url = '/api/v1/auth/login?email=owner%40example.com&password='.rawurlencode(QA_INGRESS_PASSWORD);

    post($url, [], ['Accept' => 'application/json'])->assertStatus(422);

    expect(DB::connection('master')->table('login_history')->count())->toBe(0);

    // Control, same values, form-encoded body: not JSON, so `request` bag.
    $ok = post('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_INGRESS_PASSWORD], ['Accept' => 'application/json']);

    $ok->assertOk();

    expect((string) $ok->json('access_token'))->not()->toBe('')
        ->and(DB::connection('master')->table('login_history')->count())->toBe(1);
});

it('has a URL-channel pin for every route under api/v1/auth', function () {
    $routes = app('router')->getRoutes();
    $actual = qaIngressAuthUris($routes);

    // Liveness of the extractor: a planted route and a planted URL are seen.
    Route::post('api/v1/auth/qa-planted', fn () => 'x');
    app('router')->getRoutes()->refreshNameLookups();

    expect(qaIngressAuthUris(app('router')->getRoutes()))->toContain('/api/v1/auth/qa-planted')
        ->and(qaIngressUrlPinnedUris("postJson('/api/v1/auth/qa-planted?x=1', [])"))->toBe(['/api/v1/auth/qa-planted'])
        // R2-W3, the other half: a URL named only in a comment is not a pin.
        // A route plus a comment mentioning its URL would otherwise satisfy the
        // completeness check below while nothing was ever dispatched to it.
        ->and(qaIngressUrlPinnedUris("// covered: /api/v1/auth/qa_commented2?token=x\n"))->toBe([])
        // And a segment carrying a digit or an underscore is seen at a call
        // site, which `[a-z-]+` could not do.
        ->and(qaIngressUrlPinnedUris("post('/api/v1/auth/mfa_v2?x=1', [])"))->toBe(['/api/v1/auth/mfa_v2']);

    $pinned = qaIngressUrlPinnedUris((string) file_get_contents(__DIR__.'/AuthCredentialsAreNeverReadFromTheUrlTest.php'));

    expect($actual)->toBe(['/api/v1/auth/login', '/api/v1/auth/logout', '/api/v1/auth/refresh', '/api/v1/auth/tenant'])
        ->and($pinned)->toBe($actual);
});

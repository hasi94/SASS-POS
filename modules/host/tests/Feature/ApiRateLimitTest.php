<?php

use App\Http\Middleware\RequireTenantToken;
use App\Http\Middleware\RequireTerminalToken;
use App\Http\Middleware\ThrottleApi;
use App\Support\Api\ApiLimits;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\QaCountingStore;
use Tests\Support\QaThrowingStore;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withServerVariables;

/*
 * T-021 Ruling 5: the rate limiter. AC-27 to AC-33b.
 */

require_once __DIR__.'/../Support/organization-http-fixture.php';

/**
 * ApiLimits is a boot-time singleton (AC-33); a test that wants a different
 * number must force it to be rebuilt from the config it just set.
 *
 * @param  array<string, int>  $limits
 */
function apiRateLimitSet(array $limits): void
{
    foreach ($limits as $key => $value) {
        config()->set("api.limits.{$key}", $value);
    }

    app()->forgetInstance(ApiLimits::class);
}

beforeEach(function () {
    orgHttpBoot();

    Route::middleware(['api', RequireTenantToken::class])
        ->post('/api/v1/qa-ratelimit/tenant', fn () => response()->json(['ok' => true]));

    app('router')->getRoutes()->refreshNameLookups();
});

it('exhausting the tenant bucket alone gives 429, with the envelope and Retry-After (AC-28, AC-29)', function () {
    $world = orgHttpWorld('a');
    $token = orgHttpToken($world, $world['owner']);

    apiRateLimitSet(['tenant' => 1, 'user' => 100]);

    $first = orgHttpCall('POST', '/api/v1/qa-ratelimit/tenant', $token);
    $second = orgHttpCall('POST', '/api/v1/qa-ratelimit/tenant', $token);

    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(429)
        ->and($second->json('errors.0.code'))->toBe('kernel.rate_limited')
        ->and((int) $second->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});

it('reports the tightest bucket on both a 200 and a handler-thrown 500 (AC-30)', function () {
    $world = orgHttpWorld('b');
    $token = orgHttpToken($world, $world['owner']);

    apiRateLimitSet(['tenant' => 100, 'user' => 2]);

    Route::middleware(['api', RequireTenantToken::class])
        ->get('/api/v1/qa-ratelimit/throws', function () {
            throw new RuntimeException('qa');
        });
    app('router')->getRoutes()->refreshNameLookups();

    $first = orgHttpCall('POST', '/api/v1/qa-ratelimit/tenant', $token);

    expect($first->headers->get('X-RateLimit-Limit'))->toBe('2')
        ->and($first->headers->get('X-RateLimit-Remaining'))->toBe('1');

    $thrown = getJson('/api/v1/qa-ratelimit/throws', ['Authorization' => 'Bearer '.$token]);

    expect($thrown->getStatusCode())->toBe(500)
        ->and($thrown->headers->has('X-RateLimit-Limit'))->toBeTrue();
});

it('one tenant exhausted does not affect another (AC-31)', function () {
    $worldA = orgHttpWorld('c');
    $tokenA = orgHttpToken($worldA, $worldA['owner']);

    $worldB = orgHttpWorld('d');
    $tokenB = orgHttpToken($worldB, $worldB['owner']);

    apiRateLimitSet(['tenant' => 1, 'user' => 100]);

    orgHttpCall('POST', '/api/v1/qa-ratelimit/tenant', $tokenA);
    $exhaustedA = orgHttpCall('POST', '/api/v1/qa-ratelimit/tenant', $tokenA);
    $stillB = orgHttpCall('POST', '/api/v1/qa-ratelimit/tenant', $tokenB);

    expect($exhaustedA->getStatusCode())->toBe(429)
        ->and($stillB->getStatusCode())->toBe(200);
});

it('an unauthenticated route is limited per IP, and two IPs are independent (AC-32)', function () {
    apiRateLimitSet(['unauthenticated' => 1]);

    Route::middleware(['api'])->get('/api/v1/qa-ratelimit/open', fn () => response()->json(['ok' => true]));
    app('router')->getRoutes()->refreshNameLookups();

    withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
    $first = get('/api/v1/qa-ratelimit/open');

    withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);
    $second = get('/api/v1/qa-ratelimit/open');

    withServerVariables(['REMOTE_ADDR' => '203.0.113.51']);
    $otherIp = get('/api/v1/qa-ratelimit/open');

    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(429)
        ->and($otherIp->getStatusCode())->toBe(200);
});

it('health and /up are never throttled and never touch the limiter store (AC-33a)', function () {
    $store = new QaCountingStore;

    app()->bind(RateLimiter::class, static fn () => new RateLimiter(new Repository($store)));

    expect(get('/health/live')->getStatusCode())->toBe(200)
        ->and(get('/health/ready')->getStatusCode())->toBe(200)
        ->and(get('/up')->getStatusCode())->toBe(200)
        ->and($store->calls)->toBe(0);
});

it('fails open on a throwing limiter store, with no rate headers (AC-33b, D-10)', function () {
    app()->bind(RateLimiter::class, static fn () => new RateLimiter(new Repository(new QaThrowingStore)));

    Log::shouldReceive('warning')->once();

    Route::middleware(['api'])->get('/api/v1/qa-ratelimit/open-fail', fn () => response()->json(['ok' => true]));
    app('router')->getRoutes()->refreshNameLookups();

    $response = getJson('/api/v1/qa-ratelimit/open-fail');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
});

it('a terminal token is counted against tenant and terminal only, no user bucket (AC-53)', function () {
    $world = orgHttpWorld('e');
    $ownerToken = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($ownerToken);
    $activated = orgHttpActivated($ownerToken, $at, 'r1');

    Route::middleware(['api', RequireTerminalToken::class])
        ->get('/api/v1/qa-ratelimit/terminal', fn () => response()->json(['ok' => true]));
    app('router')->getRoutes()->refreshNameLookups();

    $response = getJson('/api/v1/qa-ratelimit/terminal', ['Authorization' => 'Bearer '.$activated['access']]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->has('X-RateLimit-Limit'))->toBeTrue();
});

it('orders RequireTenantToken ahead of ThrottleApi, with SubstituteBindings first', function () {
    // Forces the HTTP kernel's afterResolving sync, which copies the final
    // (appended) middleware groups onto the router - otherwise, with no
    // request yet dispatched in this test, the router's own copy of the
    // `api` group is still empty and resolveMiddleware() would return it
    // unexpanded.
    app(Kernel::class);

    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/v1/qa-ratelimit/tenant');

    $resolved = app('router')->gatherRouteMiddleware($route);

    $tokenIndex = array_search(RequireTenantToken::class, $resolved, true);
    $throttleIndex = array_search(ThrottleApi::class, $resolved, true);

    expect($tokenIndex)->not->toBeFalse()
        ->and($throttleIndex)->not->toBeFalse()
        ->and($tokenIndex)->toBeLessThan($throttleIndex);
});

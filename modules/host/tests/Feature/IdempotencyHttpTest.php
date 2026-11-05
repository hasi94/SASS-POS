<?php

use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\RequireTenantToken;
use App\Support\Api\ApiLimits;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Api\ApiResponse;
use Flexipos\Kernel\Api\Idempotency\IdempotencyFingerprint;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\postJson;

/*
 * T-021 Ruling 6: the idempotency middleware over HTTP. AC-16 to AC-25,
 * AC-27.
 */

require_once __DIR__.'/../Support/organization-http-fixture.php';

/**
 * A second tenant, isolated from `orgHttpWorld()`'s `tenant-a` default
 * mapping, so AC-25 can prove two tenants' databases never share a row.
 *
 * @return array{user: string, tenant: string, owner: string, token: string}
 */
function idemHttpWorldB(): array
{
    $userId = authFixtureUser('owner-idem-b@example.com');
    $tenantId = authFixtureTenant('SHOP-IDEM-B');

    authFixtureMembership($tenantId, $userId);

    $record = tenancyFixtureMapping()['tenant-b'];
    $record['schema_version'] = AccessTenantFixture::topVersion();

    /** @var array<string, array<string, mixed>> $configured */
    $configured = config('tenancy.databases', []);

    config()->set('tenancy.databases', $configured + [$tenantId => $record, 'tenant-b' => $record]);

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    AccessTenantFixture::switchTo('tenant-b');

    /** @var SyncPermissionRegistryHandler $sync */
    $sync = app(SyncPermissionRegistryHandler::class);
    $sync->handle(new SyncPermissionRegistry);

    $employee = AccessSeed::employee('owner-idem-b', ['display_name' => 'Owner Idem B']);
    $role = (string) AccessSeed::connection()->table('roles')->where('key', 'owner')->value('id');
    AccessSeed::assign($employee, $role);

    $owner = $employee;
    $claims = AccessSeed::tokenClaims($owner);

    tenancy()->leave();

    /** @var IssueTokenPairHandler $tokens */
    $tokens = app(IssueTokenPairHandler::class);

    $token = $tokens->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: $userId,
        tenantId: $tenantId,
        employeeClaims: $claims,
    ))->accessToken;

    return ['user' => $userId, 'tenant' => $tenantId, 'owner' => $owner, 'token' => $token];
}

beforeEach(function () {
    orgHttpBoot();
    app()->forgetInstance(ApiLimits::class);

    $spy = ['calls' => 0];
    app()->instance('qa-idem-spy', $spy);

    Route::middleware(['api', RequireTenantToken::class, RequireIdempotencyKey::class])
        ->post('/api/v1/qa-idem/create', function (Request $request) {
            $spy = app('qa-idem-spy');
            $spy['calls']++;
            app()->instance('qa-idem-spy', $spy);

            if ($request->header('X-Qa-Outcome') === 'validation') {
                return response()->json(['errors' => ['x' => ['bad']]], 422);
            }

            if ($request->header('X-Qa-Outcome') === 'throw') {
                throw new RuntimeException('qa boom');
            }

            return ApiResponse::created(['id' => $request->input('id', 'x')]);
        });

    Route::middleware(['api', RequireTenantToken::class, RequireIdempotencyKey::class])
        ->post('/api/v1/qa-idem/other', fn () => ApiResponse::created(['ok' => true]));

    Route::middleware(['api'])
        ->post('/api/v1/qa-idem/no-tenant', fn () => ApiResponse::created(['ok' => true]))
        ->middleware(RequireIdempotencyKey::class);

    app('router')->getRoutes()->refreshNameLookups();
});

function idemSpyCalls(): int
{
    return app('qa-idem-spy')['calls'];
}

it('refuses with no key, and the handler does not run (AC-16)', function () {
    $world = orgHttpWorld('a');
    $token = orgHttpToken($world, $world['owner']);

    $response = orgHttpCall('POST', '/api/v1/qa-idem/create', $token, ['id' => '1']);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->json('errors.0.code'))->toBe('kernel.idempotency_key_required')
        ->and(idemSpyCalls())->toBe(0);
});

it('refuses an empty, an 81-character and a spaced key, and the handler does not run (AC-17)', function (string $key) {
    $world = orgHttpWorld('b');
    $token = orgHttpToken($world, $world['owner']);

    $response = postJson('/api/v1/qa-idem/create', ['id' => '1'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => $key,
    ]);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->json('errors.0.code'))->toBe('kernel.idempotency_key_invalid')
        ->and(idemSpyCalls())->toBe(0);
})->with([
    'empty' => [''],
    '81 chars' => [str_repeat('a', 81)],
    'a space' => ['key with space'],
]);

it('stores one row on a 2xx and returns the response unchanged (AC-18)', function () {
    $world = orgHttpWorld('c');
    $token = orgHttpToken($world, $world['owner']);

    $response = postJson('/api/v1/qa-idem/create', ['id' => 'abc'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-1',
    ]);

    expect($response->getStatusCode())->toBe(201)
        ->and($response->json('data.id'))->toBe('abc');

    orgHttpInTenant(function () {
        expect(DB::connection('tenant')->table('idempotency_keys')->count())->toBe(1);
    });
});

it('replays the same key: the handler does not run again, Idempotent-Replayed is set, request_id is fresh (AC-19)', function () {
    $world = orgHttpWorld('d');
    $token = orgHttpToken($world, $world['owner']);

    $first = postJson('/api/v1/qa-idem/create', ['id' => 'abc'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-2',
    ]);

    $second = postJson('/api/v1/qa-idem/create', ['id' => 'abc'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-2',
    ]);

    expect($second->getStatusCode())->toBe($first->getStatusCode())
        ->and($second->json('data'))->toBe($first->json('data'))
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and(idemSpyCalls())->toBe(1)
        ->and($second->json('meta.request_id'))->not->toBe($first->json('meta.request_id'))
        ->and($second->json('meta.request_id'))->toBe($second->headers->get('X-Request-Id'));
});

it('refuses a different body with 422 reused, discloses nothing of the first body (AC-20)', function () {
    $world = orgHttpWorld('e');
    $token = orgHttpToken($world, $world['owner']);

    postJson('/api/v1/qa-idem/create', ['id' => 'secret-marker'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-3',
    ]);

    $second = postJson('/api/v1/qa-idem/create', ['id' => 'different'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-3',
    ]);

    expect($second->getStatusCode())->toBe(422)
        ->and($second->json('errors.0.code'))->toBe('kernel.idempotency_key_reused')
        ->and((string) $second->getContent())->not->toContain('secret-marker');
});

it('refuses a different path with 422 reused (AC-20)', function () {
    $world = orgHttpWorld('f');
    $token = orgHttpToken($world, $world['owner']);

    postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-4',
    ]);

    $second = postJson('/api/v1/qa-idem/other', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-4',
    ]);

    expect($second->getStatusCode())->toBe(422)
        ->and($second->json('errors.0.code'))->toBe('kernel.idempotency_key_reused');
});

it('refuses a different actor with 422 reused (AC-20)', function () {
    // "Different actor" is the request's `sub` claim (the master user, what
    // ApiActor::identity reads) - a second owner of the *same* tenant, not
    // merely a second employee row under the fixture's one master login.
    $world = orgHttpWorld('g');
    $tokenOwner = orgHttpToken($world, $world['owner']);

    $secondUserId = authFixtureUser('owner-g-2@example.com');
    authFixtureMembership($world['tenant'], $secondUserId);
    $secondEmployee = orgHttpEmployeeHolding('owner-g-2', ['employees.view']);
    $secondClaims = orgHttpInTenant(static fn () => AccessSeed::tokenClaims($secondEmployee));

    /** @var IssueTokenPairHandler $tokens */
    $tokens = app(IssueTokenPairHandler::class);

    $tokenOther = $tokens->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: $secondUserId,
        tenantId: $world['tenant'],
        employeeClaims: $secondClaims,
    ))->accessToken;

    postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$tokenOwner,
        'Idempotency-Key' => 'qa-key-5',
    ]);

    $second = postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$tokenOther,
        'Idempotency-Key' => 'qa-key-5',
    ]);

    expect($second->getStatusCode())->toBe(422)
        ->and($second->json('errors.0.code'))->toBe('kernel.idempotency_key_reused');
});

it('stores nothing on a 422 or a throw, and a retry runs the handler again (AC-21)', function () {
    $world = orgHttpWorld('h');
    $token = orgHttpToken($world, $world['owner']);

    $validation = postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-6',
        'X-Qa-Outcome' => 'validation',
    ]);

    expect($validation->getStatusCode())->toBe(422);

    $retried = postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-6',
    ]);

    expect($retried->getStatusCode())->toBe(201)
        ->and(idemSpyCalls())->toBe(2);

    try {
        postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'qa-key-7',
            'X-Qa-Outcome' => 'throw',
        ]);
    } catch (RuntimeException) {
        // expected
    }

    $afterThrow = postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-7',
    ]);

    expect($afterThrow->getStatusCode())->toBe(201);
});

it('refuses an in-flight pre-seeded row as 409, and the handler does not run (AC-22, deterministic half)', function () {
    $world = orgHttpWorld('i');
    $token = orgHttpToken($world, $world['owner']);

    $actor = 'user:'.$world['user'];
    $hash = IdempotencyFingerprint::of('POST', 'api/v1/qa-idem/create', $actor, json_encode(['id' => 'x']));

    orgHttpInTenant(function () use ($hash) {
        DB::connection('tenant')->table('idempotency_keys')->insert([
            'key' => 'qa-key-8',
            'request_hash' => $hash,
            'response_code' => null,
            'response_body' => null,
            'created_at' => now(),
        ]);
    });

    $response = postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-8',
    ]);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->json('errors.0.code'))->toBe('kernel.idempotency_in_progress')
        ->and($response->headers->has('Retry-After'))->toBeTrue()
        ->and(idemSpyCalls())->toBe(0);
});

it('refuses a route with no resolved tenant as 500, logging and storing no row (AC-24)', function () {
    Log::shouldReceive('error')->atLeast()->once();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $response = postJson('/api/v1/qa-idem/no-tenant', ['id' => 'x'], [
        'Idempotency-Key' => 'qa-key-9',
    ]);

    expect($response->getStatusCode())->toBe(500)
        ->and($response->json('errors.0.code'))->toBe('kernel.server_error');
});

it('each tenant executes once and neither sees the others row (AC-25)', function () {
    $worldA = orgHttpWorld('j');
    $tokenA = orgHttpToken($worldA, $worldA['owner']);
    $worldB = idemHttpWorldB();

    postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$tokenA,
        'Idempotency-Key' => 'qa-shared-key',
    ]);

    postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$worldB['token'],
        'Idempotency-Key' => 'qa-shared-key',
    ]);

    orgHttpInTenant(function () {
        expect(DB::connection('tenant')->table('idempotency_keys')->where('key', 'qa-shared-key')->count())->toBe(1);
    });

    AccessTenantFixture::switchTo('tenant-b');
    expect(DB::connection('tenant')->table('idempotency_keys')->where('key', 'qa-shared-key')->count())->toBe(1);
    tenancy()->leave();

    expect(idemSpyCalls())->toBe(2);
});

it('a replay still counts against the rate limits (AC-27)', function () {
    $world = orgHttpWorld('k');
    $token = orgHttpToken($world, $world['owner']);

    config()->set('api.limits.user', 2);
    app()->forgetInstance(ApiLimits::class);

    postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-10',
    ]);

    $replay = postJson('/api/v1/qa-idem/create', ['id' => 'x'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-10',
    ]);

    expect($replay->headers->get('Idempotent-Replayed'))->toBe('true');

    $third = postJson('/api/v1/qa-idem/other', ['id' => 'y'], [
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => 'qa-key-11',
    ]);

    expect($third->getStatusCode())->toBe(429);
});

<?php

use App\Http\Middleware\RequireTenantPermission;
use App\Http\Middleware\RequireTenantToken;
use App\Http\Middleware\RequireTerminalToken;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Support\GenericRefusal;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\json;

/*
|--------------------------------------------------------------------------
| T-014 step 12 - a terminal's session: the request-time check, route
| separation and refresh (AC-34 to AC-37)
|--------------------------------------------------------------------------
|
| **The tenant database is the authority on every request, and each state that
| must refuse is its own case with its own control**: the same token that
| worked before the change is refused after it and, where the state is
| reversible, works again afterwards. The refusal is always the same body and
| gives no reason.
|
| The terminal-only route in this file is a probe controller declared below and
| mounted by the test: step 14's settings route is the shipped one, and the
| middleware under test must be reachable before it exists. It is a class and
| not a closure, because a closure route would be picked up by any later
| sweep in the same process.
|
| The family and the used marker are read from the master directly, because
| "the family is intact" and "the family is revoked" are statements about
| rows, and a refusal looks the same either way on the wire.
*/

require_once __DIR__.'/../Support/organization-http-fixture.php';

final class TerminalProbeController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}

beforeEach(function () {
    orgHttpBoot();

    Route::middleware(['api', RequireTerminalToken::class])
        ->get('/api/v1/terminal/probe', TerminalProbeController::class)
        ->name('terminal.probe');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * A world with an owner, one branch and one activated terminal.
 *
 * @return array<string, mixed>
 */
function sessionWorld(): array
{
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);
    $till = orgHttpActivated($owner, $at, 't1');

    return ['world' => $world, 'owner' => $owner, 'at' => $at, 'till' => $till];
}

function probe(string $token): int
{
    return json('GET', '/api/v1/terminal/probe', [], ['Authorization' => 'Bearer '.$token])->getStatusCode();
}

/**
 * @return array<string, mixed>
 */
function sessionTerminalRow(string $terminalId): array
{
    return orgHttpRead(static fn (): array => (array) AccessSeed::connection()->table('terminals')->where('id', $terminalId)->first());
}

/**
 * @param  array<string, mixed>  $changes
 */
function sessionSetTerminal(string $terminalId, array $changes): void
{
    orgHttpRead(static fn (): int => AccessSeed::connection()->table('terminals')->where('id', $terminalId)->update($changes));
}

/**
 * Every row of a refresh family, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function sessionFamily(string $refreshToken): array
{
    $hash = hash('sha256', $refreshToken);
    $family = (string) DB::connection('master')->table('refresh_tokens')->where('token_hash', $hash)->value('family_id');

    return DB::connection('master')->table('refresh_tokens')->where('family_id', $family)->orderBy('created_at')->orderBy('id')
        ->get()->map(static fn ($row): array => (array) $row)->all();
}

it('refuses the next request in each state that must refuse, with the same body and no reason, and works again once reversed (AC-34, AC-37)', function () {
    $s = sessionWorld();
    $till = $s['till'];
    $terminal = $till['terminal'];

    // Control: the token works before any change.
    expect(probe($till['access']))->toBe(200);

    $uniform = GenericRefusal::json();

    $states = [
        'paused' => [
            static fn () => sessionSetTerminal($terminal, ['status' => 'inactive']),
            static fn () => sessionSetTerminal($terminal, ['status' => 'active']),
        ],
        'revoked' => [
            static fn () => sessionSetTerminal($terminal, ['status' => 'revoked']),
            static fn () => sessionSetTerminal($terminal, ['status' => 'active']),
        ],
        'a revoked credential under an active terminal row' => [
            static fn () => orgHttpRead(static fn (): int => AccessSeed::connection()->table('terminal_credentials')->where('terminal_id', $terminal)->update(['status' => 'revoked'])),
            static fn () => orgHttpRead(static fn (): int => AccessSeed::connection()->table('terminal_credentials')->where('terminal_id', $terminal)->update(['status' => 'active'])),
        ],
        'pending (never activated)' => [
            static fn () => sessionSetTerminal($terminal, ['status' => 'pending']),
            static fn () => sessionSetTerminal($terminal, ['status' => 'active']),
        ],
        'soft-deleted' => [
            static fn () => sessionSetTerminal($terminal, ['deleted_at' => '2026-10-22 09:00:00.000']),
            static fn () => sessionSetTerminal($terminal, ['deleted_at' => null]),
        ],
        'moved to another branch' => [
            static function () use ($terminal, $s): void {
                $other = orgHttpBranch($s['owner'], 'br2');

                orgHttpRead(static function () use ($terminal, $other): void {
                    $c = AccessSeed::connection();
                    $location = $other['store'];
                    $c->table('terminals')->where('id', $terminal)->update(['branch_id' => $other['branch'], 'location_id' => $location]);
                });
            },
            static fn () => sessionSetTerminal($terminal, ['branch_id' => $s['at']['branch'], 'location_id' => $s['at']['store']]),
        ],
    ];

    $seen = [];

    foreach ($states as $label => [$apply, $reverse]) {
        $apply();

        $response = json('GET', '/api/v1/terminal/probe', [], ['Authorization' => 'Bearer '.$till['access']]);

        $seen[] = $label;

        // Refused at once - the token is unexpired - with the one body.
        expect($response->getStatusCode())->toBe(401, $label)
            ->and((string) $response->getContent())->toBe($uniform, $label)
            ->and(array_key_exists('retry-after', $response->headers->all()))->toBeFalse($label);

        $reverse();

        // The control: reversing the state makes the SAME token work again.
        expect(probe($till['access']))->toBe(200, $label.' (reversed)');
    }

    fwrite(STDOUT, PHP_EOL.'AC-34 states refused then restored: '.implode('; ', $seen).PHP_EOL);

    // A terminal row that is missing altogether.
    orgHttpRead(static function () use ($terminal): void {
        $c = AccessSeed::connection();
        $c->table('terminals')->where('id', $terminal)->update(['current_device_id' => null]);
        $c->table('terminal_credentials')->where('terminal_id', $terminal)->delete();
        $c->table('devices')->where('terminal_id', $terminal)->delete();
        $c->table('terminals')->where('id', $terminal)->delete();
    });

    expect(probe($till['access']))->toBe(401);
});

it('refuses a terminal token on every employee route, an employee and a platform token on terminal routes, and prints each class (AC-35, AC-18)', function () {
    $s = sessionWorld();

    $platform = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(TokenClaims::TYPE_PLATFORM, $s['world']['user']))->accessToken;

    $terminalOnly = [];
    $employee = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = array_filter($route->gatherMiddleware(), 'is_string');

        if (in_array(RequireTerminalToken::class, $middleware, true)) {
            $terminalOnly[] = implode('|', $route->methods()).' '.$route->uri();
        }

        if (in_array(RequireTenantToken::class, $middleware, true)) {
            $declared = array_values(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, RequireTenantPermission::class.':')));

            $employee[] = ['method' => $route->methods()[0], 'uri' => $route->uri(), 'declared' => $declared !== []];
        }
    }

    fwrite(STDOUT, PHP_EOL.'AC-35 terminal-only routes ('.count($terminalOnly).'): '.implode(', ', $terminalOnly).PHP_EOL
        .'AC-35 employee routes ('.count($employee).') refusing a terminal token'.PHP_EOL);

    expect($terminalOnly)->not->toBe([])
        ->and(count($employee))->toBeGreaterThan(30);

    $fill = static fn (string $uri): string => '/'.preg_replace('/\{[^}]+\}/', '01ARZ3NDEKTSV4RRFFQ69G5FAV', $uri);

    foreach ($employee as $route) {
        $response = json($route['method'], $fill($route['uri']), [], ['Authorization' => 'Bearer '.$s['till']['access']]);

        expect($response->getStatusCode())->toBe(403, $route['method'].' '.$route['uri'])
            ->and($response->json())->toBe(['error' => 'wrong_token_type'], $route['method'].' '.$route['uri']);
    }

    // The terminal-only route refuses an employee token and a platform one.
    foreach ([$s['owner'], $platform] as $token) {
        $response = json('GET', '/api/v1/terminal/probe', [], ['Authorization' => 'Bearer '.$token]);

        expect($response->getStatusCode())->toBe(403)
            ->and($response->json())->toBe(['error' => 'wrong_token_type']);
    }

    // Control: the terminal token passes its own route, and the owner passes an employee route.
    expect(probe($s['till']['access']))->toBe(200)
        ->and(orgHttpCall('GET', '/api/v1/organization/branches', $s['owner'])->getStatusCode())->toBe(200);

    // No token and a malformed one are the uniform 401.
    expect(json('GET', '/api/v1/terminal/probe')->getStatusCode())->toBe(401)
        ->and(json('GET', '/api/v1/terminal/probe', [], ['Authorization' => 'Bearer not-a-token'])->getStatusCode())->toBe(401);
});

it('rotates in the same family, stamps last_seen on the terminal and its device, and revokes the family on a replay (AC-36)', function () {
    $s = sessionWorld();
    $till = $s['till'];
    $clock = app(RunnerClock::class);

    $clock->advanceMinutes(5);

    $rotated = orgHttpRefresh($till['refresh']);

    expect($rotated->getStatusCode())->toBe(200)
        ->and(array_keys($rotated->json()))->toBe(['terminal_id', 'branch_id', 'access_token', 'refresh_token'])
        ->and($rotated->json('refresh_token'))->not->toBe($till['refresh']);

    $family = sessionFamily((string) $rotated->json('refresh_token'));

    // Two rows in ONE family: the presented one used, the new one not.
    expect($family)->toHaveCount(2)
        ->and($family[0]['used_at'])->toBe('2026-10-22 10:05:00.000')
        ->and($family[1]['used_at'])->toBeNull()
        ->and($family[1]['expires_at'])->toBe('2026-11-05 10:05:00.000')
        ->and($family[0]['family_id'])->toBe($family[1]['family_id']);

    // last_seen_at moved on the terminal and on its device.
    $row = sessionTerminalRow($till['terminal']);
    $device = orgHttpRead(static fn (): array => (array) AccessSeed::connection()->table('devices')->where('terminal_id', $till['terminal'])->first());

    expect($row['last_seen_at'])->toBe('2026-10-22 10:05:00.000')
        ->and($device['last_seen_at'])->toBe('2026-10-22 10:05:00.000');

    // The new access token is a terminal token that works.
    expect(probe((string) $rotated->json('access_token')))->toBe(200);

    // The newest token worked just now; a replay of the OLD one is refused,
    // revokes every row of the family - the newest included - and writes the event.
    $replay = orgHttpRefresh($till['refresh']);

    expect($replay->getStatusCode())->toBe(401)
        ->and((string) $replay->getContent())->toBe(GenericRefusal::json());

    foreach (sessionFamily((string) $rotated->json('refresh_token')) as $member) {
        expect($member['revoked_at'])->not->toBeNull();
    }

    expect(orgHttpRefresh((string) $rotated->json('refresh_token'))->getStatusCode())->toBe(401);

    $event = (array) DB::connection('master')->table('security_events')->where('type', 'token_reuse')->first();
    $detail = json_decode((string) $event['detail'], true);

    expect($event['user_id'])->toBeNull()
        ->and($event['tenant_id'])->toBe($s['world']['tenant'])
        ->and($detail)->toBe(['family_id' => $family[0]['family_id'], 'revoked_rows' => 2, 'terminal_id' => $till['terminal']])
        ->and((string) json_encode($event))->not->toContain((string) $rotated->json('refresh_token'))
        ->and((string) json_encode($event))->not->toContain($till['refresh']);
});

it('refuses a refresh at exactly fourteen days and accepts it a second before (AC-36)', function () {
    $s = sessionWorld();
    $clock = app(RunnerClock::class);

    $clock->advanceMinutes((14 * 24 * 60) - 1);

    $early = orgHttpRefresh($s['till']['refresh']);

    expect($early->getStatusCode())->toBe(200);

    // The new token expires 14 days after now; at that exact instant it is refused.
    $clock->advanceMinutes(14 * 24 * 60);

    expect(orgHttpRefresh((string) $early->json('refresh_token'))->getStatusCode())->toBe(401);
});

it('keeps the family and the token while paused, so the same token refreshes after the resume, and revokes it for a revoked state (AC-36, AC-37, D-35)', function () {
    $s = sessionWorld();
    $till = $s['till'];
    $terminal = $till['terminal'];

    // Paused: the generic refusal, the presented token NOT consumed, the family NOT revoked.
    sessionSetTerminal($terminal, ['status' => 'inactive']);

    $paused = orgHttpRefresh($till['refresh']);
    $family = sessionFamily($till['refresh']);

    expect($paused->getStatusCode())->toBe(401)
        ->and((string) $paused->getContent())->toBe(GenericRefusal::json())
        ->and($family)->toHaveCount(1)
        ->and($family[0]['used_at'])->toBeNull()
        ->and($family[0]['revoked_at'])->toBeNull()
        // The access token is refused at once too (AC-37).
        ->and(probe($till['access']))->toBe(401);

    // After the resume the SAME refresh token works, and so does the old access token.
    sessionSetTerminal($terminal, ['status' => 'active']);

    expect(probe($till['access']))->toBe(200);

    $resumed = orgHttpRefresh($till['refresh']);

    expect($resumed->getStatusCode())->toBe(200);

    // Revoked, a revoked credential, and a missing row each refuse AND revoke the family.
    $states = [
        'a revoked terminal' => static fn () => sessionSetTerminal($terminal, ['status' => 'revoked']),
        'a revoked credential' => static fn () => orgHttpRead(static fn (): int => AccessSeed::connection()->table('terminal_credentials')->where('terminal_id', $terminal)->update(['status' => 'revoked'])),
        'a pending terminal' => static fn () => sessionSetTerminal($terminal, ['status' => 'pending']),
    ];

    $token = (string) $resumed->json('refresh_token');

    foreach ($states as $label => $apply) {
        $apply();

        expect(orgHttpRefresh($token)->getStatusCode())->toBe(401, $label);

        foreach (sessionFamily($token) as $member) {
            expect($member['revoked_at'])->not->toBeNull("[{$label}] left a family row alive");
        }

        // Restore the state and the family for the next case.
        sessionSetTerminal($terminal, ['status' => 'active']);
        orgHttpRead(static fn (): int => AccessSeed::connection()->table('terminal_credentials')->where('terminal_id', $terminal)->update(['status' => 'active']));
        DB::connection('master')->table('refresh_tokens')->where('family_id', sessionFamily($token)[0]['family_id'])->update(['revoked_at' => null]);
    }
});

it('does not mix the two token families, and refuses a refresh while the tenant cannot be served, consuming nothing (AC-36, step 12)', function () {
    $s = sessionWorld();
    $till = $s['till'];

    // A terminal refresh token at the USER refresh endpoint: unknown there,
    // refused, and its family untouched.
    $wrongDoor = json('POST', '/api/v1/auth/refresh', ['refresh_token' => $till['refresh']]);
    $family = sessionFamily($till['refresh']);

    expect($wrongDoor->getStatusCode())->toBe(401)
        ->and($family)->toHaveCount(1)
        ->and($family[0]['used_at'])->toBeNull()
        ->and($family[0]['revoked_at'])->toBeNull();

    // A user's refresh token at the terminal endpoint: refused too.
    $userLogin = DB::connection('master')->table('users')->first();
    $userPair = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(TokenClaims::TYPE_PLATFORM, (string) $userLogin->id));

    expect(orgHttpRefresh($userPair->refreshToken)->getStatusCode())->toBe(401)
        ->and(DB::connection('master')->table('refresh_tokens')->where('id', $userPair->refreshTokenId)->value('revoked_at'))->toBeNull();

    // The tenant is suspended: refused, nothing consumed; restored, the same token works.
    DB::connection('master')->table('tenants')->where('id', $s['world']['tenant'])->update(['status' => 'suspended']);

    expect(orgHttpRefresh($till['refresh'])->getStatusCode())->toBe(401)
        ->and(sessionFamily($till['refresh'])[0]['used_at'])->toBeNull();

    DB::connection('master')->table('tenants')->where('id', $s['world']['tenant'])->update(['status' => 'active']);

    expect(orgHttpRefresh($till['refresh'])->getStatusCode())->toBe(200);

    // A non-string and an unknown value are the same uniform refusal.
    expect(orgHttpRefresh(['x'])->getStatusCode())->toBe(401)
        ->and(orgHttpRefresh('never-issued')->getStatusCode())->toBe(401)
        ->and((string) orgHttpRefresh('never-issued')->getContent())->toBe(GenericRefusal::json());
});

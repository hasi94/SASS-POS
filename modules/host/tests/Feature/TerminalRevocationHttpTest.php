<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Support\GenericRefusal;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Auth\TerminalSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-014 step 13 - revoking a terminal over HTTP (AC-38, AC-39, AC-40)
|--------------------------------------------------------------------------
|
| The real `MasterTerminalSessions` against the real master. One world is built
| per case and shared by every assertion in it.
|
| **The order is proved by capturing the statements of both connections** and
| comparing positions, because "the tenant first" is a claim about sequence and
| a state check afterwards cannot see it. The captured list is printed.
|
| Reach is proved with rows that must NOT move: another tenant's terminal that
| carries the same id, and a user's refresh token for the same user.
*/

require_once __DIR__.'/../Support/organization-http-fixture.php';

beforeEach(function () {
    orgHttpBoot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return array<string, mixed>
 */
function revokeHttpWorld(): array
{
    $world = orgHttpWorld();
    $owner = orgHttpToken($world, $world['owner']);
    $at = orgHttpBranch($owner);
    $till = orgHttpActivated($owner, $at, 't1');

    return ['world' => $world, 'owner' => $owner, 'at' => $at, 'till' => $till];
}

/**
 * Refresh rows by revoked or live, for the terminal.
 *
 * @return array{live: int, revoked: int}
 */
function revokeHttpRefreshCounts(string $principalType, string $principalId, string $tenantId): array
{
    $rows = DB::connection('master')->table('refresh_tokens')
        ->where('principal_type', $principalType)->where('principal_id', $principalId)->where('tenant_id', $tenantId);

    return [
        'live' => (clone $rows)->whereNull('revoked_at')->count(),
        'revoked' => (clone $rows)->whereNotNull('revoked_at')->count(),
    ];
}

it('revokes the tenant side first and then the master, and touches nothing of another tenant or a user (AC-38)', function () {
    $s = revokeHttpWorld();
    $till = $s['till'];
    $tenantId = $s['world']['tenant'];

    // Control rows that must not move: the same terminal id in another tenant,
    // and a user's own refresh token in this one.
    $otherTenant = authFixtureTenant('SHOP-B');
    $copy = (array) DB::connection('master')->table('refresh_tokens')->where('principal_id', $till['terminal'])->first();
    $copy['id'] = (string) Str::ulid();
    $copy['token_hash'] = hash('sha256', 'other-tenant-token');
    $copy['family_id'] = (string) Str::ulid();
    $copy['tenant_id'] = $otherTenant;
    DB::connection('master')->table('refresh_tokens')->insert($copy);

    $userPair = app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_PLATFORM,
        userId: $s['world']['user'],
    ));

    $before = [
        'terminal' => revokeHttpRefreshCounts('terminal', $till['terminal'], $tenantId),
        'other' => revokeHttpRefreshCounts('terminal', $till['terminal'], $otherTenant),
        'user' => DB::connection('master')->table('refresh_tokens')->where('principal_type', 'user')->whereNull('revoked_at')->count(),
    ];

    // Control: before the call the token works and the family is live.
    expect($before['terminal']['live'])->toBeGreaterThan(0)
        ->and($before['terminal']['revoked'])->toBe(0)
        ->and($before['other'])->toBe(['live' => 1, 'revoked' => 0])
        ->and($before['user'])->toBeGreaterThan(0)
        ->and(orgHttpRefresh($till['refresh'])->getStatusCode())->toBe(200);

    $statements = [];

    DB::listen(static function ($query) use (&$statements): void {
        if (preg_match('/^(update|insert|delete)\b/i', (string) $query->sql) === 1) {
            $statements[] = $query->connectionName.': '.preg_replace('/\s+/', ' ', substr((string) $query->sql, 0, 60));
        }
    });

    try {
        $response = orgHttpCall('POST', '/api/v1/organization/terminals/'.$till['terminal'].'/revoke', $s['owner']);
    } finally {
        Event::forget('Illuminate\Database\Events\QueryExecuted');
    }

    fwrite(STDOUT, PHP_EOL.'AC-38 writes in order:'.PHP_EOL.implode(PHP_EOL, $statements).PHP_EOL);

    $position = static function (string $needle) use ($statements): int {
        foreach ($statements as $i => $line) {
            if (str_contains($line, $needle)) {
                return $i;
            }
        }

        return -1;
    };

    $tenantWrite = $position('update "terminals"');
    $audit = $position('insert into "audit_logs"');
    $masterWrite = $position('update "refresh_tokens"');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json())->toBe(['terminal_id' => $till['terminal'], 'status' => 'revoked', 'sessions_revoked' => true])
        ->and($tenantWrite)->toBeGreaterThanOrEqual(0)
        ->and($audit)->toBeGreaterThan($tenantWrite)
        ->and($masterWrite)->toBeGreaterThan($audit)
        ->and(str_starts_with($statements[$masterWrite], 'master:'))->toBeTrue()
        ->and(str_starts_with($statements[$tenantWrite], 'master:'))->toBeFalse();

    $after = revokeHttpRefreshCounts('terminal', $till['terminal'], $tenantId);

    expect($after['live'])->toBe(0)
        ->and($after['revoked'])->toBeGreaterThan(0)
        // The other tenant's row and the users' tokens did not move.
        ->and(revokeHttpRefreshCounts('terminal', $till['terminal'], $otherTenant))->toBe($before['other'])
        ->and(DB::connection('master')->table('refresh_tokens')->where('principal_type', 'user')->whereNull('revoked_at')->count())->toBe($before['user'])
        ->and($userPair->refreshToken)->not->toBe('');

    // The terminal's tokens are now refused with the one body.
    $refresh = orgHttpRefresh($till['refresh']);

    expect($refresh->getStatusCode())->toBe(401)
        ->and((string) $refresh->getContent())->toBe(GenericRefusal::json());
});

it('answers sessions_revoked false when the master step fails, and a repeat retries it without a second audit row (AC-39)', function () {
    $s = revokeHttpWorld();
    $till = $s['till'];
    $tenantId = $s['world']['tenant'];

    // One double, bound before the first request: the route caches its
    // controller and so its handler, so the switch has to live inside it.
    $real = app(TerminalSessions::class);
    $switch = new class($real) implements TerminalSessions
    {
        public bool $down = true;

        public function __construct(private readonly TerminalSessions $real) {}

        public function tenantPartFor(string $tenantId): string
        {
            return $this->real->tenantPartFor($tenantId);
        }

        public function revokeAll(string $tenantId, string $terminalId): int
        {
            if ($this->down) {
                throw new RuntimeException('master down');
            }

            return $this->real->revokeAll($tenantId, $terminalId);
        }
    };

    app()->instance(TerminalSessions::class, $switch);
    $first = orgHttpCall('POST', '/api/v1/organization/terminals/'.$till['terminal'].'/revoke', $s['owner']);

    $row = orgHttpRead(static fn (): array => (array) AccessSeed::connection()->table('terminals')->where('id', $till['terminal'])->first());
    $auditCount = orgHttpRead(static fn (): int => AccessSeed::connection()->table('audit_logs')->where('action', 'terminal_revoked')->count());

    expect($first->getStatusCode())->toBe(200)
        ->and($first->json('sessions_revoked'))->toBeFalse()
        ->and($row['status'])->toBe('revoked')
        ->and($auditCount)->toBe(1)
        // The master families are still live: this is the state "false" reports.
        ->and(revokeHttpRefreshCounts('terminal', $till['terminal'], $tenantId)['live'])->toBeGreaterThan(0)
        // And the terminal is nonetheless refused at once, on the tenant's word.
        ->and(orgHttpRefresh($till['refresh'])->getStatusCode())->toBe(401);

    $switch->down = false;

    $second = orgHttpCall('POST', '/api/v1/organization/terminals/'.$till['terminal'].'/revoke', $s['owner']);

    expect($second->getStatusCode())->toBe(200)
        ->and($second->json('sessions_revoked'))->toBeTrue()
        ->and(revokeHttpRefreshCounts('terminal', $till['terminal'], $tenantId)['live'])->toBe(0)
        ->and(orgHttpRead(static fn (): int => AccessSeed::connection()->table('audit_logs')->where('action', 'terminal_revoked')->count()))->toBe(1);
});

it('has no route that un-revokes, refuses the status route for a revoked terminal, and keeps the permission and the 404 (AC-40)', function () {
    $s = revokeHttpWorld();
    $till = $s['till'];

    $url = '/api/v1/organization/terminals/'.$till['terminal'];

    // Controls first: a caller without the key is refused, an unknown id is 404.
    $nobody = orgHttpEmployeeHolding('nobody', ['terminals.view']);
    $denied = orgHttpCall('POST', $url.'/revoke', orgHttpToken($s['world'], $nobody));

    expect($denied->getStatusCode())->toBe(403)
        ->and(orgHttpCall('POST', '/api/v1/organization/terminals/'.Str::ulid().'/revoke', $s['owner'])->getStatusCode())->toBe(404);

    expect(orgHttpCall('POST', $url.'/revoke', $s['owner'])->getStatusCode())->toBe(200);

    foreach (['active', 'inactive'] as $asked) {
        $response = orgHttpCall('PUT', $url.'/status', $s['owner'], ['status' => $asked]);

        expect($response->getStatusCode())->toBe(403)
            ->and($response->json('error'))->toBe('terminal_revoked');
    }

    // Activation and re-issue of a code for it do not bring it back either.
    $reissue = orgHttpCall('POST', $url.'/activation-code', $s['owner']);

    expect($reissue->getStatusCode())->toBeGreaterThanOrEqual(400);

    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_contains($route->uri(), 'terminals')) {
            $routes[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    fwrite(STDOUT, PHP_EOL.'AC-40 terminal routes: '.implode('; ', $routes).PHP_EOL);

    expect(array_filter($routes, static fn (string $r): bool => preg_match('/un-?revoke|restore|reinstate|reactivate/i', $r) === 1))->toBe([]);

    expect(orgHttpRead(static fn (): string => (string) AccessSeed::connection()->table('terminals')->where('id', $till['terminal'])->value('status')))->toBe('revoked');
});

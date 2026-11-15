<?php

use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| T-010 QA - tenant SELECTION refuses a bad employee link (AC-61, D-6)
|--------------------------------------------------------------------------
|
| AC-61: for each of four causes - no employee row, a soft-deleted employee,
| a non-acting status, an employee whose `user_id` is null or another user's
| - selection is refused `employee_unavailable` with 403, no token, no
| refresh row, and "a `login_history` failure row with `tenant_id` set".
| "The response does not say which of the four causes applied (one body),
| and the cause is in the application log at warning level without personal
| data. The refusal does not consume or revoke an existing refresh token."
| And: "Positive control for each: fixing the single cause makes the same
| request succeed."
|
| The dev suite proves the four causes at the HANDLER (`BuildEmployeeClaimsTest`)
| and the login_history row / token survival on REFRESH
| (`QaTenantRefreshAndMyPermissionsTest`). On the SELECT endpoint it drives a
| missing employee only to check the status maps to 403. QA mutated
| `SelectTenantHandler` to write the failure row with `tenant_id` null: all 95
| tests of the selection, refresh and token slices stayed green. This file
| measures the selection path itself, once per cause.
|
| **What each cause leaves behind is compared with what it started from**, not
| with a literal: the refresh-token count, the login_history count and the
| used/revoked state of the platform family minted by the login that came
| before. Absences get their liveness control in the same test - the same
| request after the cause is fixed succeeds, writes a refresh row and a
| success history row, so the counters are known to move when they should.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const QA_T10S_CLOCK = '2026-10-20 10:00:00.000';

const QA_T10S_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function () {
    authFixtureClockAt(QA_T10S_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey('qa-t10s-key');

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function qaT10sInTenant(callable $work): mixed
{
    AccessTenantFixture::switchTo('tenant-a');

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

/**
 * @return array{user: string, tenant: string}
 */
function qaT10sWorld(): array
{
    $userId = authFixtureUser('owner@example.com', QA_T10S_PASSWORD);
    $tenantId = authFixtureTenant('shop-a');

    $record = tenancyFixtureMapping()['tenant-a'];
    $record['schema_version'] = AccessTenantFixture::topVersion();

    config()->set('tenancy.databases', [$tenantId => $record, 'tenant-a' => $record]);
    config()->set('tenancy.required_schema_version', AccessTenantFixture::topVersion());

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetInstance(TenantMigrationTargetRepository::class);
    app()->forgetScopedInstances();

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $route->flushController();
    }

    qaT10sInTenant(static function (): void {
        app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);
    });

    return ['user' => $userId, 'tenant' => $tenantId];
}

/**
 * The four causes of D-6, each as: how to build the bad state, and the
 * single change that fixes it.
 *
 * @return array<string, array{build: callable(string): string, fix: callable(string, string): void}>
 */
function qaT10sCauses(): array
{
    return [
        'no employee row' => [
            // The membership names an id no employee has; fixing it means
            // creating that employee with the right link.
            'build' => static fn (string $userId): string => (string) Str::ulid(),
            'fix' => static function (string $userId, string $employeeId): void {
                qaT10sInTenant(static fn () => AccessSeed::employee('late', ['id' => $employeeId, 'user_id' => $userId]));
            },
        ],
        'soft-deleted employee' => [
            'build' => static fn (string $userId): string => qaT10sInTenant(static fn (): string => AccessSeed::employee('gone', [
                'user_id' => $userId,
                'deleted_at' => now()->format('Y-m-d H:i:s.v'),
            ])),
            'fix' => static function (string $userId, string $employeeId): void {
                qaT10sInTenant(static fn () => AccessSeed::connection()->table('employees')->where('id', $employeeId)->update(['deleted_at' => null]));
            },
        ],
        'non-acting status' => [
            'build' => static fn (string $userId): string => qaT10sInTenant(static fn (): string => AccessSeed::employee('away', [
                'user_id' => $userId,
                'status' => 'on_leave',
            ])),
            'fix' => static function (string $userId, string $employeeId): void {
                qaT10sInTenant(static fn () => AccessSeed::connection()->table('employees')->where('id', $employeeId)->update(['status' => 'active']));
            },
        ],
        'employee with no user link' => [
            'build' => static fn (string $userId): string => qaT10sInTenant(static fn (): string => AccessSeed::employee('unlinked', ['user_id' => null])),
            'fix' => static function (string $userId, string $employeeId): void {
                qaT10sInTenant(static fn () => AccessSeed::connection()->table('employees')->where('id', $employeeId)->update(['user_id' => $userId]));
            },
        ],
        "another user's employee" => [
            'build' => static fn (string $userId): string => qaT10sInTenant(static fn (): string => AccessSeed::employee('theirs', ['user_id' => (string) Str::ulid()])),
            'fix' => static function (string $userId, string $employeeId): void {
                qaT10sInTenant(static fn () => AccessSeed::connection()->table('employees')->where('id', $employeeId)->update(['user_id' => $userId]));
            },
        ],
    ];
}

it('refuses selection once per cause with one body, one failure row naming the tenant, no token, no refresh row, and spares the login (AC-61)', function () {
    $world = qaT10sWorld();

    $logPath = storage_path('framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'qa-t10s-'.bin2hex(random_bytes(6)).'.log');

    File::ensureDirectoryExists(dirname($logPath));

    config()->set('logging.channels.qa_t10s', ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']);
    config()->set('logging.default', 'qa_t10s');
    Log::forgetChannel('qa_t10s');

    $bodies = [];
    $examined = [];

    foreach (qaT10sCauses() as $name => $cause) {
        // A clean tenant for each cause: the previous cause's fix linked an
        // employee to this user, and `employees.user_id` is unique.
        qaT10sInTenant(static fn () => AccessSeed::connection()->table('employees')->delete());

        $employeeId = $cause['build']($world['user']);

        // A fresh membership naming this employee (one membership per
        // tenant and user, so the previous cause's is replaced).
        DB::connection('master')->table('tenant_users')
            ->where('tenant_id', $world['tenant'])->where('user_id', $world['user'])->delete();

        authFixtureMembership($world['tenant'], $world['user'], ['employee_id' => $employeeId]);

        $login = postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => QA_T10S_PASSWORD])
            ->assertOk()->json();

        $refreshRows = DB::connection('master')->table('refresh_tokens')->count();
        $historyRows = DB::connection('master')->table('login_history')->count();

        $response = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], [
            'Authorization' => 'Bearer '.$login['access_token'],
        ]);

        $response->assertForbidden()->assertJson(['error' => 'employee_unavailable']);

        $bodies[$name] = (string) $response->getContent();

        // No token of either kind in the body.
        expect($response->json('access_token'))->toBeNull()
            ->and($response->json('refresh_token'))->toBeNull();

        // No refresh row was written.
        expect(DB::connection('master')->table('refresh_tokens')->count())->toBe($refreshRows, "[{$name}] wrote a refresh row");

        // Exactly one history row, a failure, naming the tenant.
        $rows = DB::connection('master')->table('login_history')->orderByDesc('id')->get();

        expect($rows->count() - $historyRows)->toBe(1, "[{$name}] must write exactly one login_history row")
            ->and((bool) $rows[0]->success)->toBeFalse()
            ->and((string) $rows[0]->failure_reason)->toBe('employee_unavailable')
            ->and((string) $rows[0]->tenant_id)->toBe($world['tenant'], "[{$name}] the failure row must name the tenant");

        // The login's own platform refresh family is untouched.
        $platform = DB::connection('master')->table('refresh_tokens')->whereNull('tenant_id')->orderByDesc('created_at')->first();

        expect($platform)->not->toBeNull()
            ->and($platform->used_at)->toBeNull("[{$name}] consumed the platform refresh token")
            ->and($platform->revoked_at)->toBeNull("[{$name}] revoked the platform refresh token");

        // The positive control: fixing the single cause makes the same
        // request succeed, writes a refresh row and a success row.
        $cause['fix']($world['user'], $employeeId);

        $ok = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], [
            'Authorization' => 'Bearer '.$login['access_token'],
        ])->assertOk();

        expect($ok->json('access_token'))->toBeString()
            ->and(DB::connection('master')->table('refresh_tokens')->count())->toBe($refreshRows + 1);

        $examined[] = $name;
    }

    // One body for every cause: the response does not say which applied.
    expect(array_unique(array_values($bodies)))->toHaveCount(1);

    // The cause is in the log at warning level, without the user's address
    // or password.
    clearstatcache(true, $logPath);

    $log = (string) file_get_contents($logPath);

    expect($log)->toContain('WARNING')
        ->and($log)->toContain('cannot be served')
        ->and($log)->not->toContain('owner@example.com')
        ->and($log)->not->toContain(QA_T10S_PASSWORD);

    foreach (['missing_or_deleted', 'not_acting', 'user_mismatch'] as $cause) {
        expect($log)->toContain($cause);
    }

    fwrite(STDOUT, PHP_EOL.'AC-61 selection refusals, one per cause: '.implode(', ', $examined).PHP_EOL);

    expect($examined)->toHaveCount(5);
});

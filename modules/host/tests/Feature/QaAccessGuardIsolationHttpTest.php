<?php

use App\Http\Middleware\RequireTenantPermission;
use App\Http\Middleware\RequireTenantToken;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Registry\PermissionRegistry;
use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPair;
use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Access\EmployeeClaims;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\json;
use function Pest\Laravel\withServerVariables;

/*
|--------------------------------------------------------------------------
| T-010 QA - what a refused request leaves behind, and what a response says
|--------------------------------------------------------------------------
|
| Four criteria whose HTTP half the dev suite states and does not measure:
|
| - **AC-64**: "the handler spy is never called, nothing is written to the
|   route's tables (row counts unchanged)" for EVERY guarded route. The dev
|   test checks the `access_log` row for every route and the spy on ONE probe
|   route; it never looks at the route's own tables, so a controller that
|   wrote before the guard ran would pass. Here each guarded route is called
|   with a VALID body by an employee holding every key but its own, and a
|   snapshot of every tenant table except `access_log` must be identical
|   before and after. The snapshot's liveness is a real write by an
|   employee holding only the key, which must change it.
| - **AC-65**: "none contains `pin_hash`, `pin_failed_attempts`,
|   `pin_locked_until` or `perm_version` for other employees" for "any
|   response of this task". The dev test reads the two GET routes. Every
|   mutating route returns an employee view, a role view or a small ack, and
|   each is read here, headers included.
| - **AC-69**: "a request refused for validation or authentication (401, 422)
|   writes no `access_log` row", an IPv4 and IPv6 caller round-tripping, a
|   `detail` that is valid JSON and a `created_at` that is now.
| - **AC-47 over HTTP**: the PIN rules at the wire - a JSON number is a
|   non-string, an empty body is refused, the stored value is Argon2id, the
|   lock and counter are cleared and `perm_version` does not move.
|
| **The bodies are valid.** A refusal test whose body is also malformed is
| refused by validation whether or not the guard exists (mask shape 1), so
| every route below is sent the body that WOULD succeed, and the control
| shows that body succeeding for an employee holding the key.
|
| No engine: the master is the in-memory SQLite stand-in and the tenant a file
| under storage/app. Nothing reaches 127.0.0.1:3306, which is on hold.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const QA_T10G_CLOCK = '2026-10-20 10:00:00.000';

beforeEach(function () {
    authFixtureClockAt(QA_T10G_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey('qa-t10g-key');

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return array<string, string>
 */
function qaT10gWorld(): array
{
    $userId = authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, $userId);

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

    qaT10gInTenant(static function (): void {
        app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);
    });

    return ['user' => $userId, 'tenant' => $tenantId];
}

function qaT10gInTenant(callable $work): mixed
{
    AccessTenantFixture::switchTo('tenant-a');

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

function qaT10gToken(string $userId, string $tenantId, ?string $employeeId): string
{
    return app(IssueTokenPairHandler::class)->handle(new IssueTokenPair(
        tokenType: TokenClaims::TYPE_TENANT,
        userId: $userId,
        tenantId: $tenantId,
        employeeClaims: $employeeId === null ? null : qaT10gInTenant(
            static fn (): EmployeeClaims => AccessSeed::tokenClaims($employeeId)
        ),
    ))->accessToken;
}

/**
 * @param  array<string, string>  $world
 * @return array<string, string>
 */
function qaT10gBearer(array $world, string $employeeId): array
{
    return ['Authorization' => 'Bearer '.qaT10gToken($world['user'], $world['tenant'], $employeeId)];
}

/**
 * @return list<string>
 */
function qaT10gRegisteredKeys(): array
{
    $keys = array_map(
        static fn ($declaration): string => $declaration->key,
        app(PermissionRegistry::class)->declarations(),
    );

    sort($keys);

    return $keys;
}

/**
 * A fingerprint of every tenant table except the log being asserted
 * separately, taken inside the tenant.
 *
 * @return array<string, string>
 */
function qaT10gSnapshot(): array
{
    /** @var array<string, string> $snapshot */
    $snapshot = qaT10gInTenant(static function (): array {
        $connection = AccessSeed::connection();
        $snapshot = [];

        foreach ($connection->select("select name from sqlite_master where type = 'table' order by name") as $row) {
            $name = (string) ((array) $row)['name'];

            if (str_starts_with($name, 'sqlite_') || in_array($name, ['migrations', 'access_log'], true)) {
                continue;
            }

            $snapshot[$name] = md5((string) json_encode($connection->table($name)->get()->all()));
        }

        return $snapshot;
    });

    return $snapshot;
}

/**
 * Every tenant route with its declaration, read from the router.
 *
 * @return list<array{method: string, uri: string, declaration: string}>
 */
function qaT10gRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

        if (! in_array(RequireTenantToken::class, $middleware, true)) {
            continue;
        }

        $declarations = [];

        foreach ($middleware as $name) {
            if (str_starts_with($name, RequireTenantPermission::class.':')) {
                $declarations[] = substr($name, strlen(RequireTenantPermission::class) + 1);
            }
        }

        if (count($declarations) !== 1) {
            continue;
        }

        $routes[] = [
            'method' => $route->methods()[0],
            'uri' => $route->uri(),
            'declaration' => $declarations[0],
        ];
    }

    return $routes;
}

/**
 * The fixture ids a request path and body are built from.
 *
 * @return array<string, string>
 */
function qaT10gSeed(): array
{
    /** @var array<string, string> $ids */
    $ids = qaT10gInTenant(static function (): array {
        $branch = AccessSeed::branch();
        $subject = AccessSeed::employee('subject');
        $held = AccessSeed::role('held_role', ['roles.view']);

        AccessSeed::attach($subject, $branch);
        AccessSeed::assign($subject, $held);
        AccessSeed::personal($subject, 'roles.view', true);

        return [
            'branch' => $branch,
            // A second real branch for the attach body (T-014 AC-52: a body
            // naming no branch is no longer one that would succeed).
            'attachable' => AccessSeed::branch(),
            'subject' => $subject,
            'held' => $held,
            // Unassigned, so DELETE can succeed on it.
            'free' => AccessSeed::role('free_role', []),
        ];
    });

    return $ids;
}

/**
 * Method, path and the body that would SUCCEED for one route.
 *
 * @param  array<string, string>  $ids
 * @return array{string, string, array<string, mixed>}
 */
function qaT10gRequestFor(string $method, string $uri, array $ids): array
{
    $path = '/'.str_replace(
        ['{employee}', '{role}', '{permissionKey}', '{branch}'],
        [$ids['subject'], $uri === 'api/v1/access/employees/{employee}/roles/{role}' ? $ids['held'] : $ids['free'], 'roles.view', $ids['branch']],
        $uri
    );

    $bodies = [
        'POST api/v1/access/roles' => ['key' => 'qa_new_role', 'name' => 'QA new role'],
        'PATCH api/v1/access/roles/{role}' => ['name' => 'Renamed by QA'],
        'PUT api/v1/access/roles/{role}/permissions' => ['permission_keys' => ['roles.view']],
        'POST api/v1/access/employees' => ['code' => 'QA-NEW', 'first_name' => 'Quinn'],
        'PATCH api/v1/access/employees/{employee}' => ['first_name' => 'Changed'],
        'PUT api/v1/access/employees/{employee}/status' => ['status' => 'suspended'],
        'POST api/v1/access/employees/{employee}/roles' => ['role_id' => $ids['free']],
        'PUT api/v1/access/employees/{employee}/permissions/{permissionKey}' => ['allowed' => false],
        'POST api/v1/access/employees/{employee}/branches' => ['branch_id' => $ids['attachable']],
        'PUT api/v1/access/employees/{employee}/pin' => ['pin' => '7391'],
    ];

    return [$method, $path, $bodies[$method.' '.$uri] ?? []];
}

it('writes nothing to any table when a guarded route refuses, with the body that would have succeeded (AC-64)', function () {
    $world = qaT10gWorld();
    $ids = qaT10gSeed();

    $guarded = array_values(array_filter(
        qaT10gRoutes(),
        static fn (array $r): bool => $r['declaration'] !== RequireTenantPermission::AUTHENTICATED_ONLY
    ));

    // **The routes that skip the permission check are pinned by name.** AC-63
    // accepts the `authenticated_only` marker as a valid declaration and the
    // dev sweeps skip such routes in their refusal loops, so a sensitive
    // route re-declared `authenticated_only` is invisible to all of them
    // (QA ran that mutant on DELETE /employees/{employee}: every dev HTTP
    // test stayed green). A new entry here needs a conscious edit.
    $skipping = array_values(array_map(
        static fn (array $r): string => $r['method'].' '.$r['uri'],
        array_filter(
            qaT10gRoutes(),
            static fn (array $r): bool => $r['declaration'] === RequireTenantPermission::AUTHENTICATED_ONLY
        )
    ));

    expect($skipping)->toBe(['GET api/v1/access/me/permissions']);

    $actors = [];
    $examined = [];

    foreach ($guarded as $route) {
        $declaration = $route['declaration'];

        if (! isset($actors[$declaration])) {
            $actors[$declaration] = qaT10gInTenant(static fn (): string => AccessSeed::actorHolding(
                'not-'.substr(md5($declaration), 0, 8),
                array_values(array_filter(qaT10gRegisteredKeys(), static fn (string $key): bool => $key !== $declaration))
            ));
        }

        [$method, $path, $body] = qaT10gRequestFor($route['method'], $route['uri'], $ids);

        $before = qaT10gSnapshot();

        json($method, $path, $body, qaT10gBearer($world, $actors[$declaration]))
            ->assertForbidden()
            ->assertJson(['error' => 'not_permitted']);

        $after = qaT10gSnapshot();

        expect($after)->toBe($before, "[{$method} {$route['uri']}] changed a tenant table although the guard refused it");

        $examined[] = $method.' '.$route['uri'].' -> '.$declaration;
    }

    // The domain, published, and non-empty.
    fwrite(STDOUT, PHP_EOL.'AC-64 write isolation, '.count($examined).' guarded routes:'.PHP_EOL.implode(PHP_EOL, $examined).PHP_EOL);

    expect($examined)->not->toBe([])
        ->and(count($examined))->toBeGreaterThanOrEqual(19);
});

it('changes the snapshot when the same bodies are sent by an employee holding only the key (AC-64, liveness of the snapshot)', function () {
    $world = qaT10gWorld();
    $ids = qaT10gSeed();

    $writing = [
        ['POST', 'api/v1/access/roles', 'roles.manage'],
        ['PATCH', 'api/v1/access/employees/{employee}', 'employees.manage'],
        ['DELETE', 'api/v1/access/roles/{role}', 'roles.manage'],
        ['PUT', 'api/v1/access/employees/{employee}/pin', 'employees.set_pin'],
        ['DELETE', 'api/v1/access/employees/{employee}/roles/{role}', 'access.assign'],
    ];

    foreach ($writing as [$method, $uri, $key]) {
        $actor = qaT10gInTenant(static fn (): string => AccessSeed::actorHolding('only-'.substr(md5($method.$uri), 0, 8), [$key]));

        [$verb, $path, $body] = qaT10gRequestFor($method, $uri, $ids);

        $before = qaT10gSnapshot();

        $response = json($verb, $path, $body, qaT10gBearer($world, $actor));

        expect($response->getStatusCode())->toBeLessThan(300, "[{$method} {$uri}] with its key: ".$response->getContent());

        // A snapshot that cannot see a write would report "unchanged" for the
        // refusals above whatever they did.
        expect(qaT10gSnapshot())->not->toBe($before, "[{$method} {$uri}] succeeded and the snapshot did not move");
    }
});

it('returns no secret field in the response to any mutating route, headers included (AC-65)', function () {
    $world = qaT10gWorld();
    $ids = qaT10gSeed();

    $actor = qaT10gInTenant(static fn (): string => AccessSeed::actorHolding('superuser', qaT10gRegisteredKeys()));
    $headers = qaT10gBearer($world, $actor);

    // Make the subject's secret fields non-trivial, so a response that
    // echoed the row would contain them.
    qaT10gInTenant(static function () use ($ids): void {
        AccessSeed::connection()->table('employees')->where('id', $ids['subject'])->update([
            'pin_hash' => 'a-stored-digest-qa',
            'pin_failed_attempts' => 3,
            'pin_locked_until' => '2026-10-20 11:00:00.000',
        ]);
    });

    $newBranch = qaT10gInTenant(static fn (): string => AccessSeed::branch());

    $calls = [
        ['POST', '/api/v1/access/roles', ['key' => 'qa_resp_role', 'name' => 'QA']],
        ['PATCH', '/api/v1/access/roles/'.$ids['free'], ['name' => 'QA renamed']],
        ['PUT', '/api/v1/access/roles/'.$ids['free'].'/permissions', ['permission_keys' => ['roles.view']]],
        ['GET', '/api/v1/access/roles', []],
        ['GET', '/api/v1/access/roles/'.$ids['free'], []],
        ['POST', '/api/v1/access/employees', ['code' => 'QA-RESP', 'first_name' => 'Resp']],
        ['PATCH', '/api/v1/access/employees/'.$ids['subject'], ['first_name' => 'Renamed']],
        ['PUT', '/api/v1/access/employees/'.$ids['subject'].'/status', ['status' => 'probation']],
        ['POST', '/api/v1/access/employees/'.$ids['subject'].'/roles', ['role_id' => $ids['free']]],
        ['PUT', '/api/v1/access/employees/'.$ids['subject'].'/permissions/employees.view', ['allowed' => true]],
        ['DELETE', '/api/v1/access/employees/'.$ids['subject'].'/permissions/employees.view', []],
        ['POST', '/api/v1/access/employees/'.$ids['subject'].'/branches', ['branch_id' => $newBranch]],
        ['DELETE', '/api/v1/access/employees/'.$ids['subject'].'/branches/'.$newBranch, []],
        ['PUT', '/api/v1/access/employees/'.$ids['subject'].'/pin', ['pin' => '7391']],
        ['GET', '/api/v1/access/employees', []],
        ['GET', '/api/v1/access/employees/'.$ids['subject'], []],
        ['DELETE', '/api/v1/access/employees/'.$ids['subject'].'/roles/'.$ids['free'], []],
        ['DELETE', '/api/v1/access/roles/'.$ids['free'], []],
        ['DELETE', '/api/v1/access/employees/'.$ids['subject'], []],
    ];

    $forbidden = ['pin_hash', 'pin_failed_attempts', 'pin_locked_until', 'perm_version', 'a-stored-digest-qa', '$argon2id'];
    $statuses = [];

    foreach ($calls as [$method, $path, $body]) {
        $response = json($method, $path, $body, $headers);

        $text = (string) $response->getContent().' '.(string) json_encode($response->headers->all());

        $statuses[] = $method.' '.$path.' -> '.$response->getStatusCode();

        // Liveness: the call really did what it says (a 4xx would have
        // proved nothing about the success-shaped bodies).
        expect($response->getStatusCode())->toBeLessThan(300, "[{$method} {$path}] ".$response->getContent());

        foreach ($forbidden as $needle) {
            expect(str_contains($text, $needle))->toBeFalse("[{$method} {$path}] response carries [{$needle}]");
        }
    }

    fwrite(STDOUT, PHP_EOL.'AC-65 responses read ('.count($calls).'):'.PHP_EOL.implode(PHP_EOL, $statuses).PHP_EOL);

    expect($statuses)->toHaveCount(count($calls));
});

it('writes no access_log row for an authentication failure or a validation failure, and none for a success (AC-69)', function () {
    $world = qaT10gWorld();

    $actor = qaT10gInTenant(static fn (): string => AccessSeed::actorHolding('manager', qaT10gRegisteredKeys()));
    $valid = qaT10gBearer($world, $actor);

    $logRows = static fn (): int => (int) qaT10gInTenant(static fn (): int => AccessSeed::connection()->table('access_log')->count());

    $before = $logRows();

    // 401: no credential, a garbage credential.
    json('GET', '/api/v1/access/roles', [], [])->assertUnauthorized();
    json('GET', '/api/v1/access/roles', [], ['Authorization' => 'Bearer not.a.token'])->assertUnauthorized();

    // 403 for the wrong kind of token is authentication-shaped too: a
    // platform token on a tenant route.
    $login = json('POST', '/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'correct-horse-battery-staple']);

    if ($login->getStatusCode() === 200) {
        json('GET', '/api/v1/access/roles', [], ['Authorization' => 'Bearer '.$login->json('access_token')])->assertForbidden();
    }

    // 422: valid token, held key, malformed bodies on five routes.
    $invalid = [
        ['POST', '/api/v1/access/roles', []],
        ['POST', '/api/v1/access/employees', ['code' => 123, 'first_name' => 'X']],
        ['PUT', '/api/v1/access/employees/'.$actor.'/pin', ['pin' => '1234']],
        ['PUT', '/api/v1/access/employees/'.$actor.'/status', ['status' => 'not-a-status']],
        ['PUT', '/api/v1/access/roles/'.str_repeat('Z', 26).'/permissions', ['permission_keys' => ['roles.view']]],
    ];

    foreach ($invalid as [$method, $path, $body]) {
        json($method, $path, $body, $valid)->assertStatus(422);
    }

    expect($logRows())->toBe($before, 'a 401 or a 422 wrote an access_log row');

    // The control: the same actor and a valid body succeed, still with no
    // access_log row (a success is audited, not logged as an access event)...
    json('POST', '/api/v1/access/roles', ['key' => 'qa_ok_role', 'name' => 'OK'], $valid)->assertCreated();

    expect($logRows())->toBe($before);

    // ...and a real denial DOES write one, so the counter is live.
    $nobody = qaT10gInTenant(static fn (): string => AccessSeed::employee('nobody'));

    json('GET', '/api/v1/access/roles', [], qaT10gBearer($world, $nobody))->assertForbidden();

    expect($logRows())->toBe($before + 1);
});

it('round-trips an IPv4 and an IPv6 caller through a permission_denied row with valid JSON detail and the clock as created_at (AC-69)', function () {
    $world = qaT10gWorld();

    $nobody = qaT10gInTenant(static fn (): string => AccessSeed::employee('nobody'));
    $headers = qaT10gBearer($world, $nobody);

    foreach (['203.0.113.7', '2001:db8::1', '::ffff:203.0.113.8'] as $address) {
        withServerVariables(['REMOTE_ADDR' => $address]);

        $before = (int) qaT10gInTenant(static fn (): int => AccessSeed::connection()->table('access_log')->count());

        json('GET', '/api/v1/access/employees', [], $headers)->assertForbidden();

        /** @var list<array<string, mixed>> $rows */
        $rows = qaT10gInTenant(static fn (): array => AccessSeed::connection()->table('access_log')
            ->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all());

        expect($rows)->toHaveCount($before + 1);

        $row = $rows[count($rows) - 1];

        $decoded = json_decode((string) $row['detail'], true);

        expect((string) $row['type'])->toBe(AccessLogWriter::TYPE_PERMISSION_DENIED)
            ->and(json_last_error())->toBe(JSON_ERROR_NONE)
            ->and($decoded)->toBeArray()
            ->and((string) $row['created_at'])->toBe(QA_T10G_CLOCK)
            ->and(AccessLogWriter::unpackIp($row['ip'] === null ? null : (string) $row['ip']))->toBe($address);
    }
});

it('stores an Argon2id hash for a PIN set over HTTP, clears the lock, leaves the version and refuses a JSON number (AC-47)', function () {
    $world = qaT10gWorld();
    $ids = qaT10gSeed();

    $actor = qaT10gInTenant(static fn (): string => AccessSeed::actorHolding('pin-setter', ['employees.set_pin']));
    $headers = qaT10gBearer($world, $actor);

    qaT10gInTenant(static function () use ($ids): void {
        AccessSeed::connection()->table('employees')->where('id', $ids['subject'])->update([
            'pin_failed_attempts' => 4,
            'pin_locked_until' => '2026-10-20 11:00:00.000',
        ]);
    });

    $path = '/api/v1/access/employees/'.$ids['subject'].'/pin';
    $versionBefore = qaT10gInTenant(static fn (): int => AccessSeed::permVersion($ids['subject']));

    // Refusals first: a JSON number, an empty body, a list, a weak run, a
    // short and a long PIN, and two that are not all digits. (Surrounding
    // whitespace is deliberately not in this list: the framework's
    // TrimStrings middleware trims it before the policy sees the value, so
    // '7391 ' is stored as '7391'. Reported as a note, not pinned here.) Each leaves the row exactly as it was.
    $rowBefore = qaT10gInTenant(static fn (): ?array => AccessSeed::employeeRow($ids['subject']));

    $answers = [];

    foreach ([['pin' => 7391], [], ['pin' => ['7391']], ['pin' => '4321'], ['pin' => '123'], ['pin' => '123456789'], ['pin' => '73-91'], ['pin' => '7x91']] as $body) {
        $answers[(string) json_encode($body)] = json('PUT', $path, $body, $headers)->getStatusCode();
    }

    // One assertion over all of them, so a failure names every body that was
    // accepted rather than the first.
    expect($answers)->toBe(array_fill_keys(array_keys($answers), 422));

    expect(qaT10gInTenant(static fn (): ?array => AccessSeed::employeeRow($ids['subject'])))->toBe($rowBefore);

    // The success.
    $response = json('PUT', $path, ['pin' => '7391'], $headers)->assertOk();

    $row = qaT10gInTenant(static fn (): ?array => AccessSeed::employeeRow($ids['subject']));

    expect((string) $response->getContent())->not->toContain('7391')
        ->and($row['pin_hash'])->toStartWith('$argon2id$')
        ->and(app('hash')->check('7391', (string) $row['pin_hash']))->toBeTrue()
        ->and((int) $row['pin_failed_attempts'])->toBe(0)
        ->and($row['pin_locked_until'])->toBeNull()
        ->and((string) $row['pin_set_at'])->toBe(QA_T10G_CLOCK)
        ->and((int) $row['perm_version'])->toBe($versionBefore);

    // An actor may set their OWN PIN.
    $own = qaT10gBearer($world, $actor);

    json('PUT', '/api/v1/access/employees/'.$actor.'/pin', ['pin' => '5826'], $own)->assertOk();

    // The control for all of it: the same request without the key is refused,
    // so the successes above were the key's doing.
    $keyless = qaT10gInTenant(static fn (): string => AccessSeed::employee('keyless'));

    json('PUT', $path, ['pin' => '5826'], qaT10gBearer($world, $keyless))->assertForbidden();

    expect(DB::connection('master')->getDriverName())->toBe('sqlite');
});

it('refuses protected and non-string fields at the wire and leaves the row alone (AC-25, AC-26)', function () {
    $world = qaT10gWorld();
    $ids = qaT10gSeed();

    $actor = qaT10gInTenant(static fn (): string => AccessSeed::actorHolding('hr', ['employees.manage']));
    $headers = qaT10gBearer($world, $actor);
    $path = '/api/v1/access/employees/'.$ids['subject'];

    $rowBefore = qaT10gInTenant(static fn (): ?array => AccessSeed::employeeRow($ids['subject']));

    foreach ([
        ['code' => 'NEWCODE'],
        ['user_id' => (string) Str::ulid()],
        ['pin_hash' => 'x'],
        ['perm_version' => 99],
        ['status' => 'terminated'],
        ['first_name' => 'Fine', 'status' => 'terminated'],
        ['first_name' => 123],
        ['first_name' => ['a']],
    ] as $body) {
        json('PATCH', $path, $body, $headers)->assertStatus(422);
    }

    expect(qaT10gInTenant(static fn (): ?array => AccessSeed::employeeRow($ids['subject'])))->toBe($rowBefore);

    // Control: the same request without those fields succeeds.
    json('PATCH', $path, ['first_name' => 'Fine'], $headers)->assertOk();

    // Create: a non-string in any field is a validation failure, a valid body is not.
    foreach ([
        ['code' => 123, 'first_name' => 'A'],
        ['code' => 'OK1', 'first_name' => ['A']],
        ['code' => 'OK2', 'first_name' => 'A', 'last_name' => 5],
        ['code' => 'OK3', 'first_name' => 'A', 'user_id' => 'not-a-ulid'],
        ['code' => 'OK 4', 'first_name' => 'A'],
        ['code' => str_repeat('A', 21), 'first_name' => 'A'],
    ] as $body) {
        json('POST', '/api/v1/access/employees', $body, $headers)->assertStatus(422);
    }

    json('POST', '/api/v1/access/employees', ['code' => str_repeat('A', 20), 'first_name' => 'A'], $headers)->assertCreated();
});

it('clamps the employee list to the configured page size and falls back for a bad one (AC-29)', function () {
    $world = qaT10gWorld();

    qaT10gInTenant(static function (): void {
        foreach (['E1', 'E2', 'E3', 'E4'] as $code) {
            AccessSeed::employee($code);
        }
    });

    $viewer = qaT10gInTenant(static fn (): string => AccessSeed::actorHolding('E0', ['employees.view']));
    $headers = qaT10gBearer($world, $viewer);

    config()->set('access.employees.page_size', 2);
    config()->set('access.employees.max_page_size', 3);

    $codes = static fn (string $query): array => array_column(
        json('GET', '/api/v1/access/employees'.$query, [], $headers)->assertOk()->json('employees') ?? json('GET', '/api/v1/access/employees'.$query, [], $headers)->json(),
        'code'
    );

    expect($codes(''))->toBe(['E0', 'E1'])
        ->and($codes('?page_size=3'))->toBe(['E0', 'E1', 'E2'])
        ->and($codes('?page_size=1000'))->toBe(['E0', 'E1', 'E2'])
        ->and($codes('?page_size=0'))->toBe(['E0', 'E1'])
        ->and($codes('?page_size=-1'))->toBe(['E0', 'E1'])
        ->and($codes('?page_size=abc'))->toBe(['E0', 'E1']);
});

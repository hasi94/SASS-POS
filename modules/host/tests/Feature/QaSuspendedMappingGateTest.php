<?php

use App\Http\Middleware\RequireTenantToken;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotActive;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutExceptionHandling;

/*
|--------------------------------------------------------------------------
| T-009 step 13: AC-47's behavioural half - the kernel refuses, on status
|--------------------------------------------------------------------------
|
| **What this file closes.** AC-47 says authentication holds no copy of the
| tenant-database status list. Deptrac cannot prove that: it is blind to value
| copying (a bare `['active','read_only']` with `in_array()` over it, a quoted
| FQCN, a `[FQCN,'method']` callable and `new $className` were all measured to
| produce zero violations), so a green `composer deptrac` proves only that
| authentication holds no **type reference** to the three status types. What
| carries the copied-list residual is this file: a token authentication issued
| in good faith meets a mapping whose database status is not `active`, and
| the thing that refuses is the kernel, with an exception authentication never
| constructs.
|
| **The gap in the existing evidence, and how this closes it.** The unmapped-
| tenant case in TenantTokenMiddlewareTest proves the kernel refuses, but not
| that it refuses **on status**: an absent mapping row fails for a different
| reason. Here the only thing that changes between the control and the
| refusal is the mapping's `status` field. The test asserts that precondition
| itself (the two mapping rows differ in exactly one key) rather than trusting
| the fixture, because that is what makes "refused on status" a statement
| about the status and not about a host, a port or a credential.
|
| **The refusal is attributed, at runtime, to the kernel.** The thrown
| exception is a `TenantDatabaseNotActive`; the file it was thrown from is
| inside packages/kernel; the frame that called its maker is a kernel class.
| None of these is a search of source text: they are properties of the
| exception object a running request produced.
|
| **The oracle is the kernel's, not this file's.** Which statuses serve is
| read from `TenantConnectionPurpose::SERVING_STATUSES`. Typing a list here
| would be a second copy of the very thing the criterion forbids, in the one
| place that checks for it. A status the kernel serves must reach the handler,
| and every other status must not, whatever it is.
|
| **The spy is asserted in both directions in every test** (plan section 7,
| shape 5): the `active` fixture calls it exactly once, and an uncalled spy is
| otherwise what an unwired route also reports.
|
| No engine: the master is the in-memory stand-in, both tenant databases are
| T-003's SQLite files.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const QA_GATE_CLOCK = '2026-10-16 12:00:00.000';

const QA_GATE_KID = 'qa-gate-key';

const QA_GATE_URI = '/t9/qa-gate-probe';

/**
 * The handler behind the guarded route.
 */
final class QaGateSpy
{
    public int $calls = 0;

    public ?string $marker = null;
}

function qaGateSpy(): QaGateSpy
{
    $spy = new QaGateSpy;

    Route::middleware(RequireTenantToken::class)->post(QA_GATE_URI, function () use ($spy) {
        $spy->calls++;
        $spy->marker = (string) DB::connection('tenant')->scalar('select value from marker');

        return response()->json(['ok' => true]);
    });

    return $spy;
}

/**
 * T-003's `tenant-a` mapping row, with exactly one field changed: the status.
 *
 * Everything else - driver, host, port, database file, username, credential
 * reference - is `tenant-a`'s, so a refusal can only be about the status.
 *
 * @return array<string, mixed>
 */
function qaGateMappingWithStatus(string $status): array
{
    $row = tenancyFixtureMapping()['tenant-a'];
    $row['status'] = $status;

    return $row;
}

/**
 * Key the tenancy mapping by the master's tenant id.
 *
 * @param  array<string, mixed>  $row
 */
function qaGateMap(string $tenantId, array $row): void
{
    config()->set('tenancy.databases', [$tenantId => $row]);

    app()->forgetInstance(TenantDatabaseRepository::class);
    app()->forgetScopedInstances();
}

/**
 * A tenant token obtained the way a client obtains one: a login, then a
 * selection, both over HTTP, both through authentication's own code.
 *
 * @return array{token: string, tenantId: string}
 */
function qaGateIssuedTenantToken(): array
{
    authFixtureUser('owner@example.com');
    $tenantId = authFixtureTenant('shop-a');

    authFixtureMembership($tenantId, (string) DB::connection('master')->table('users')->value('id'));

    $login = postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'correct-horse-battery-staple'])
        ->assertOk()
        ->json();

    $selected = postJson('/api/v1/auth/tenant', ['code' => 'shop-a'], ['Authorization' => 'Bearer '.$login['access_token']])
        ->assertOk()
        ->json();

    expect($selected['tenant_id'])->toBe($tenantId);

    return ['token' => (string) $selected['access_token'], 'tenantId' => $tenantId];
}

/**
 * Send the request that carries a tenant token to the guarded route and hand
 * back what escaped, or null when the request was served.
 *
 * Run with exception handling off so the exception the middleware let
 * through is the object under test rather than the rendered 500 it becomes.
 */
function qaGateRefusal(string $token): ?Throwable
{
    withoutExceptionHandling();

    try {
        postJson(QA_GATE_URI, [], ['Authorization' => 'Bearer '.$token])->assertOk();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}

beforeEach(function () {
    authFixtureClockAt(QA_GATE_CLOCK);
    authFixtureMigrateMaster();
    authFixtureInstallKey(QA_GATE_KID);
    tenancyBootFixture();
});

afterEach(function () {
    tenancyForgetFixture();
});

afterAll(function () {
    tenancyDeleteFixtureRoot();
});

it('serves the active mapping once and refuses the same token when only the mapping status changes', function () {
    // AC-47, the discriminating input (shape 6): one token, one tenant, one
    // database file, three mapping states that differ in `status` alone.
    $issued = qaGateIssuedTenantToken();
    $spy = qaGateSpy();

    $active = qaGateMappingWithStatus('active');
    $suspended = qaGateMappingWithStatus('suspended');

    // The precondition, asserted: the two rows differ in exactly one key, and
    // it is the status. Without this the test could be about a host.
    expect(array_keys(array_diff_assoc($active, $suspended)))->toBe(['status'])
        ->and(array_keys(array_diff_assoc($suspended, $active)))->toBe(['status']);

    // (1) The control. The active mapping reaches the handler exactly once,
    // and the row it read came out of tenant A's file.
    qaGateMap($issued['tenantId'], $active);

    expect(qaGateRefusal($issued['token']))->toBeNull()
        ->and($spy->calls)->toBe(1)
        ->and($spy->marker)->toBe('tenant-a');

    // (2) The refusal. Same token, same tenant, same file; the status is
    // `suspended`. The handler is not called again.
    qaGateMap($issued['tenantId'], $suspended);

    $refusal = qaGateRefusal($issued['token']);

    expect($refusal)->toBeInstanceOf(TenantDatabaseNotActive::class)
        ->and($spy->calls)->toBe(1);

    // The message names the tenant and the status that decided it - the
    // refusal is attributable to status, and to this tenant.
    expect($refusal?->getMessage())->toBe(
        TenantDatabaseNotActive::forTenant($issued['tenantId'], 'suspended')->getMessage()
    );

    // (3) And back. Reinstating the mapping serves the token again, so the
    // refusal in (2) was caused by the status and not by anything the token,
    // the clock or the route had acquired in between.
    qaGateMap($issued['tenantId'], $active);

    expect(qaGateRefusal($issued['token']))->toBeNull()
        ->and($spy->calls)->toBe(2);
});

it('attributes the refusal to the kernel: thrown from packages/kernel, by a kernel caller, never by authentication', function () {
    $issued = qaGateIssuedTenantToken();
    $spy = qaGateSpy();

    qaGateMap($issued['tenantId'], qaGateMappingWithStatus('suspended'));

    $refusal = qaGateRefusal($issued['token']);

    expect($refusal)->toBeInstanceOf(TenantDatabaseNotActive::class);

    /** @var Throwable $refusal */
    $kernelRoot = realpath(base_path('../../packages/kernel/src'));

    expect($kernelRoot)->not->toBeFalse();

    // Frame 0 is the call of the maker, `TenantDatabaseNotActive::forTenant`;
    // its `file` is where that call was written. getFile() on the exception is
    // deliberately not used: Pest rewrites an escaping exception's own file and
    // line to its postJson() wrapper (measured: Http.php:219), but leaves the
    // trace frames alone. The call site is inside the kernel.
    $trace = $refusal->getTrace();

    expect(str_starts_with((string) realpath((string) ($trace[0]['file'] ?? '')), (string) $kernelRoot))->toBeTrue('the maker was called from '.($trace[0]['file'] ?? '?'));

    // Frame 1 is whoever called the code at frame 0: a kernel class, which is
    // the only owner the criterion allows.
    expect($trace[0]['class'] ?? null)->toBe(TenantDatabaseNotActive::class)
        ->and($trace[0]['function'])->toBe('forTenant')
        ->and(str_starts_with((string) ($trace[1]['class'] ?? ''), 'Flexipos\\Kernel\\'))->toBeTrue('the maker was called from '.($trace[1]['class'] ?? '?'));

    // No frame between the maker and the middleware belongs to authentication:
    // the package that issued the token had no hand in the decision. The
    // control is that the middleware frame IS in the trace, so the walk is
    // looking at the right stack.
    $classes = array_map(static fn (array $frame): string => (string) ($frame['class'] ?? ''), $trace);
    $middleware = array_search(RequireTenantToken::class, $classes, true);

    expect($middleware)->not->toBeFalse();

    foreach (array_slice($classes, 0, (int) $middleware) as $class) {
        expect(str_starts_with($class, 'Flexipos\\Auth\\'))->toBeFalse('authentication is on the refusal path: '.$class);
    }

    expect($spy->calls)->toBe(0);
});

it('refuses exactly the statuses the kernel does not serve, and serves exactly the ones it does', function () {
    // The cross product the planner/gate vocabulary mismatch (T-008) taught:
    // every status an operator can plausibly write, run through the real path,
    // judged against the kernel's own list. A copy inside authentication that
    // had drifted from it shows up as a status on the wrong side.
    $issued = qaGateIssuedTenantToken();
    $spy = qaGateSpy();

    $statuses = [
        'active', 'suspended', 'migrating', 'read_only', 'failed', 'provisioning',
        'moving', 'restoring', 'archived', 'pending', 'ACTIVE', ' active', 'active ', '',
    ];

    $served = [];
    $refused = [];

    foreach ($statuses as $status) {
        $before = $spy->calls;

        qaGateMap($issued['tenantId'], qaGateMappingWithStatus($status));

        $outcome = qaGateRefusal($issued['token']);

        if ($outcome === null) {
            $served[] = $status;

            expect($spy->calls)->toBe($before + 1, 'status ['.$status.'] was served but the handler did not run');

            continue;
        }

        $refused[] = $status;

        expect($outcome)->toBeInstanceOf(TenantDatabaseNotActive::class, 'status ['.$status.'] was refused by '.$outcome::class)
            ->and($spy->calls)->toBe($before, 'status ['.$status.'] was refused but the handler ran');

        // Nothing is left half-open for the next request.
        expect(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());
    }

    $kernelServes = TenantConnectionPurpose::SERVING_STATUSES;

    expect($served)->toBe(array_values(array_intersect($statuses, $kernelServes)))
        // Liveness for both sides: something was served, many things were not.
        ->and($served)->toBe(['active'])
        ->and(count($refused))->toBe(count($statuses) - 1)
        ->and($spy->calls)->toBe(1);
});

it('leaves no tenant resolved and the tenant stanza a placeholder after the kernel refuses', function () {
    $issued = qaGateIssuedTenantToken();
    $spy = qaGateSpy();

    qaGateMap($issued['tenantId'], qaGateMappingWithStatus('suspended'));

    expect(qaGateRefusal($issued['token']))->toBeInstanceOf(TenantDatabaseNotActive::class)
        ->and(app(Tenancy::class)->current()->hasTenant())->toBeFalse()
        ->and(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza())
        ->and($spy->calls)->toBe(0);

    // The control: the same process serves the active mapping next.
    qaGateMap($issued['tenantId'], qaGateMappingWithStatus('active'));

    expect(qaGateRefusal($issued['token']))->toBeNull()
        ->and($spy->calls)->toBe(1);
});

it('reports what a suspended shop\'s till actually receives through the real exception handler', function () {
    // Not a criterion: a measurement the QA report records. AC-47 asks for a
    // refusal by the existing gate and gets one; this pins what the client
    // sees, so a later task that renders the kernel's refusal as a 4xx or 503
    // changes this test on purpose rather than by accident.
    $issued = qaGateIssuedTenantToken();
    $spy = qaGateSpy();

    qaGateMap($issued['tenantId'], qaGateMappingWithStatus('suspended'));

    $response = postJson(QA_GATE_URI, [], ['Authorization' => 'Bearer '.$issued['token']]);

    expect($response->getStatusCode())->toBe(500)
        ->and($spy->calls)->toBe(0);

    qaGateMap($issued['tenantId'], qaGateMappingWithStatus('active'));

    postJson(QA_GATE_URI, [], ['Authorization' => 'Bearer '.$issued['token']])->assertOk();

    expect($spy->calls)->toBe(1);
});

it('serves a tenant token one second before exp and refuses it at exactly exp, the handler never running on the refusal', function () {
    // dev's weakness 5, the tenant half: the boundary through the middleware
    // that also enters the tenant. The token is issued at the stopped clock;
    // its exp is 15 minutes later.
    $issued = qaGateIssuedTenantToken();
    $spy = qaGateSpy();

    qaGateMap($issued['tenantId'], qaGateMappingWithStatus('active'));

    authFixtureClockAt('2026-10-16 12:14:59.000');

    postJson(QA_GATE_URI, [], ['Authorization' => 'Bearer '.$issued['token']])->assertOk();

    expect($spy->calls)->toBe(1);

    authFixtureClockAt('2026-10-16 12:15:00.000');

    postJson(QA_GATE_URI, [], ['Authorization' => 'Bearer '.$issued['token']])->assertUnauthorized();

    expect($spy->calls)->toBe(1)
        ->and(tenancyTenantStanza())->toBe(tenancyPlaceholderStanza());
});

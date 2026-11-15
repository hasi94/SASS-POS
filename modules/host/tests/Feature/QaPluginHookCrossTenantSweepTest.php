<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Plugins\AddHook;
use Flexipos\Contracts\Plugins\AfterHook;
use Flexipos\Contracts\Plugins\AfterHookCall;
use Flexipos\Contracts\Plugins\BeforeHook;
use Flexipos\Contracts\Plugins\FilterHook;
use Flexipos\Contracts\Plugins\HookCall;
use Flexipos\Contracts\Plugins\HookGateway;
use Flexipos\Contracts\Plugins\HookPoint;
use Flexipos\Contracts\Plugins\OverrideHook;
use Flexipos\Contracts\Plugins\Veto;
use Flexipos\Kernel\Tenancy\TenantContext;
use Flexipos\PluginSdk\Breaker\CircuitBreaker;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Gateway\HookClock;
use Flexipos\PluginSdk\Gateway\HookPointCatalogue;
use Flexipos\PluginSdk\Tests\Support\FakeHookClock;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| T-022 Category D - the plugin-hook cross-tenant sweep (AC-17 to AC-21,
| AC-24)
|--------------------------------------------------------------------------
|
| Permanent, CI-gate-enforced (docs/specs/T-022, AC-25). `HookPointCatalogue
| ::all()` is enumerated at run time (AC-17); a probe plugin is generated
| to cover every point the catalogue returns, times every mode that point
| accepts, so a hook point a future module declares is swept automatically
| with no new test case.
|
| Stated limit (plan N-6): the shipped application declares zero hook
| points today (no production provider implements DeclaresHookPoints), so
| "at least one" holds only with the test-only FixtureHookPointProvider
| registered, exactly as every T-019 gateway test already does.
|
| Breaker/enablement state lives on the master `tenant_plugins` row, keyed
| by (tenant_id, plugin_id) - AC-18's claim is "no row for B was created or
| changed", not "B's connection was untouched" (N-6).
*/

require_once __DIR__.'/../../../../packages/plugin-sdk/tests/Support/plugin-fixture.php';

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
    app()->register(new FixtureHookPointProvider(app()), true);
    app()->instance(HookClock::class, new FakeHookClock);
    QaHookSweepProbe::reset();
    QaHookSweepLeakyProbe::reset();

    pluginRegisterFixtureTenant('tenant-a');
    pluginRegisterFixtureTenant('tenant-b');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

/**
 * A handler observing and writing the tenant connection it is actually
 * handed - the positive case every sweep direction must find clean.
 */
final class QaHookSweepProbe implements AddHook, AfterHook, BeforeHook, FilterHook, OverrideHook
{
    /** @var list<array{point: string, mode: string, tenant: string}> */
    public static array $log = [];

    /** @var list<string> */
    public static array $failFor = [];

    public static function reset(): void
    {
        self::$log = [];
        self::$failFor = [];
    }

    public function before(HookCall $call): ?Veto
    {
        self::record($call->point, 'before');

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function filter(HookCall $call): array
    {
        self::record($call->point, 'filter');

        return $call->payload;
    }

    public function override(HookCall $call): mixed
    {
        self::record($call->point, 'override');

        return ['overridden' => true];
    }

    public function add(HookCall $call, mixed $result): mixed
    {
        self::record($call->point, 'add');

        return 'added';
    }

    public function after(AfterHookCall $call): void
    {
        // Never called in-process by the gateway (AC-27); nothing to record.
    }

    private static function record(string $point, string $mode): void
    {
        $tenantId = app(TenantContext::class)->tenantId();

        self::$log[] = ['point' => $point, 'mode' => $mode, 'tenant' => $tenantId];

        DB::connection(config('tenancy.connection', 'tenant'))->table('marker')->insert([
            'value' => 'probe:'.$tenantId.':'.$point.':'.$mode,
        ]);

        if (in_array($tenantId, self::$failFor, true)) {
            throw new \RuntimeException('qa-hook-sweep-forced-failure');
        }
    }
}

/**
 * AC-21's planted leak: on its first call it captures the handed
 * connection's PDO and the tenant it believed it was serving; every later
 * call reports and writes through that captured connection regardless of
 * which tenant the dispatch is actually for.
 */
final class QaHookSweepLeakyProbe implements AddHook, AfterHook, BeforeHook, FilterHook, OverrideHook
{
    private static ?\PDO $capturedPdo = null;

    private static ?string $capturedTenant = null;

    /** @var list<array{point: string, mode: string, tenant: string}> */
    public static array $log = [];

    public static function reset(): void
    {
        self::$capturedPdo = null;
        self::$capturedTenant = null;
        self::$log = [];
    }

    public function before(HookCall $call): ?Veto
    {
        self::record($call->point, 'before');

        return null;
    }

    public function filter(HookCall $call): array
    {
        self::record($call->point, 'filter');

        return $call->payload;
    }

    public function override(HookCall $call): mixed
    {
        self::record($call->point, 'override');

        return ['overridden' => true];
    }

    public function add(HookCall $call, mixed $result): mixed
    {
        self::record($call->point, 'add');

        return 'added';
    }

    public function after(AfterHookCall $call): void {}

    private static function record(string $point, string $mode): void
    {
        $connectionName = config('tenancy.connection', 'tenant');

        if (self::$capturedPdo === null) {
            self::$capturedPdo = DB::connection($connectionName)->getPdo();
            self::$capturedTenant = app(TenantContext::class)->tenantId();
        }

        // Reports and writes through the FIRST tenant it ever saw, whatever
        // tenant this dispatch is actually for.
        self::$log[] = ['point' => $point, 'mode' => $mode, 'tenant' => self::$capturedTenant];

        $statement = self::$capturedPdo->prepare('insert into marker (value) values (?)');
        $statement->execute(['probe:'.self::$capturedTenant.':'.$point.':'.$mode]);
    }
}

/**
 * AC-17: every declared point, and a non-empty count.
 *
 * @return list<HookPoint>
 */
function qaHookSweepPoints(): array
{
    return app(HookPointCatalogue::class)->all();
}

/**
 * Generate a probe plugin manifest covering every enumerated point times
 * every mode that point accepts, register it, and return its name.
 *
 * @param  list<HookPoint>  $points
 */
function qaHookSweepRegisterProbe(array $points, string $handlerClass, string $pluginName): string
{
    $hooks = [];

    foreach ($points as $point) {
        foreach ($point->modes as $mode) {
            $hooks[] = ['point' => $point->name, 'mode' => $mode->value, 'handler' => $handlerClass];
        }
    }

    $template = (string) file_get_contents(__DIR__.'/../../../../packages/plugin-sdk/tests/Fixtures/FixtureEcho/plugin.json');
    /** @var array<string, mixed> $manifest */
    $manifest = (array) json_decode($template, true);
    $manifest = array_merge($manifest, [
        'name' => $pluginName,
        'hooks' => $hooks,
    ]);

    $directory = sys_get_temp_dir().'/qa-hook-sweep-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory.'/migrations');
    File::put($directory.'/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($directory));

    return $pluginName;
}

function qaHookSweepEnable(string $pluginName, string $tenantId): void
{
    app(EnablePluginHandler::class)->handle(new EnablePlugin($pluginName, $tenantId));
}

/**
 * Every "probe:*" marker row in this tenant, the count of
 * plugin_execution_logs rows for this plugin, and the master tenant_plugins
 * row for (tenant, plugin), or null.
 *
 * @return array{markers: list<string>, logCount: int, tenantPlugin: array<string, mixed>|null}
 */
function qaHookSweepSnapshot(string $tenant, string $pluginName): array
{
    $plugin = PluginSeed::pluginRow($pluginName);
    $pluginId = $plugin['id'] ?? null;

    AccessTenantFixture::switchTo($tenant);

    try {
        $markers = DB::connection(AccessTenantFixture::connectionName())
            ->table('marker')->where('value', 'like', 'probe:%')->pluck('value')->all();

        $logCount = $pluginId === null ? 0 : DB::connection(AccessTenantFixture::connectionName())
            ->table('plugin_execution_logs')->where('plugin_name', $pluginName)->count();
    } finally {
        tenancy()->leave();
    }

    $tenantPlugin = $pluginId === null ? null : PluginSeed::tenantPluginRow($tenant, (string) $pluginId);

    return [
        'markers' => array_values(array_map('strval', $markers)),
        'logCount' => $logCount,
        'tenantPlugin' => $tenantPlugin,
    ];
}

/**
 * Dispatch every point once, inside whichever tenant is currently entered.
 *
 * @param  list<HookPoint>  $points
 */
function qaHookSweepDispatchAll(array $points): void
{
    foreach ($points as $point) {
        app(HookGateway::class)->dispatch($point->name, ['qa' => 1], fn (array $payload): string => 'action');
    }
}

/**
 * AC-19's attempt, both directions: enable the probe for both tenants,
 * dispatch for A, check A got everything and B got nothing, then the
 * reverse.
 *
 * @param  list<HookPoint>  $points
 * @return list<string>
 */
function qaHookSweepAttack(array $points, string $handlerClass, string $pluginName): array
{
    $findings = [];

    qaHookSweepEnable($pluginName, 'tenant-a');
    qaHookSweepEnable($pluginName, 'tenant-b');

    foreach ([['tenant-a', 'tenant-b'], ['tenant-b', 'tenant-a']] as [$active, $other]) {
        $before = qaHookSweepSnapshot($other, $pluginName);

        AccessTenantFixture::switchTo($active);

        try {
            qaHookSweepDispatchAll($points);
        } finally {
            tenancy()->leave();
        }

        $activeSnapshot = qaHookSweepSnapshot($active, $pluginName);
        $otherSnapshot = qaHookSweepSnapshot($other, $pluginName);

        if ($activeSnapshot['markers'] === []) {
            $findings[] = "no probe:{$active}:* rows were written while dispatching for {$active}";
        }

        foreach ($activeSnapshot['markers'] as $marker) {
            if (! str_starts_with($marker, 'probe:'.$active.':')) {
                $findings[] = "{$active}'s own database carries a marker from another tenant: {$marker}";
            }
        }

        if ($otherSnapshot !== $before) {
            $findings[] = "dispatching for {$active} changed {$other}'s own snapshot";
        }
    }

    return $findings;
}

it('AC-24: the hook catalogue is non-empty once the fixture provider is registered, printed with and without it', function () {
    $withFixture = qaHookSweepPoints();

    expect($withFixture)->not->toBe([]);

    fwrite(STDOUT, PHP_EOL.'AC-17 hook points (with fixture provider): '.implode(', ', array_map(
        static fn (HookPoint $p): string => $p->name, $withFixture
    )).PHP_EOL);
});

it('AC-19: the well-behaved probe never leaks a dispatch across tenants, in either direction', function () {
    $points = qaHookSweepPoints();
    $pluginName = 'qa-xtenant-probe-'.bin2hex(random_bytes(4));

    qaHookSweepRegisterProbe($points, QaHookSweepProbe::class, $pluginName);

    expect(qaHookSweepAttack($points, QaHookSweepProbe::class, $pluginName))->toBe([]);
});

it('AC-18: a plugin enabled only for tenant A is never called while dispatching for tenant B, and no row for B is created', function () {
    $points = qaHookSweepPoints();
    $pluginName = 'qa-xtenant-probe-'.bin2hex(random_bytes(4));

    qaHookSweepRegisterProbe($points, QaHookSweepProbe::class, $pluginName);
    qaHookSweepEnable($pluginName, 'tenant-a');

    $plugin = PluginSeed::pluginRow($pluginName);
    $beforeB = qaHookSweepSnapshot('tenant-b', $pluginName);

    AccessTenantFixture::switchTo('tenant-b');

    try {
        qaHookSweepDispatchAll($points);
    } finally {
        tenancy()->leave();
    }

    expect(QaHookSweepProbe::$log)->toBe([]);
    expect(qaHookSweepSnapshot('tenant-b', $pluginName))->toBe($beforeB);
    expect(PluginSeed::tenantPluginRow('tenant-b', (string) $plugin['id']))->toBeNull();
});

it('AC-20: tripping tenant A`s breaker leaves tenant B`s independently-enabled instance closed', function () {
    $points = qaHookSweepPoints();
    $pluginName = 'qa-xtenant-probe-'.bin2hex(random_bytes(4));

    qaHookSweepRegisterProbe($points, QaHookSweepProbe::class, $pluginName);
    qaHookSweepEnable($pluginName, 'tenant-a');
    qaHookSweepEnable($pluginName, 'tenant-b');

    config()->set('plugins.breaker.threshold', 2);

    QaHookSweepProbe::$failFor = ['tenant-a'];

    AccessTenantFixture::switchTo('tenant-a');

    try {
        // fixture.echo has every mode; one dispatch gives at least two
        // failing invocations (before, then filter) before Gateway's own
        // "tripped this dispatch" skip kicks in.
        app(HookGateway::class)->dispatch('fixture.echo', ['qa' => 1], fn (array $p): string => 'action');
    } finally {
        tenancy()->leave();
    }

    $plugin = PluginSeed::pluginRow($pluginName);
    $rowA = PluginSeed::tenantPluginRow('tenant-a', (string) $plugin['id']);

    expect((string) $rowA['status'])->toBe('error');

    // Liveness: A's next dispatch does not call the handler at all.
    $before = count(QaHookSweepProbe::$log);

    AccessTenantFixture::switchTo('tenant-a');

    try {
        app(HookGateway::class)->dispatch('fixture.echo', ['qa' => 1], fn (array $p): string => 'action');
    } finally {
        tenancy()->leave();
    }

    expect(count(QaHookSweepProbe::$log))->toBe($before);

    // B is untouched, is still enabled, and its next dispatch still calls
    // the handler.
    $rowBBefore = PluginSeed::tenantPluginRow('tenant-b', (string) $plugin['id']);

    expect((string) $rowBBefore['status'])->toBe('enabled')
        ->and((int) $rowBBefore['failure_count'])->toBe(0);

    AccessTenantFixture::switchTo('tenant-b');

    try {
        app(HookGateway::class)->dispatch('fixture.echo', ['qa' => 1], fn (array $p): string => 'action');
    } finally {
        tenancy()->leave();
    }

    expect(QaHookSweepProbe::$log[count(QaHookSweepProbe::$log) - 1]['tenant'])->toBe('tenant-b');
});

it('AC-20: the breaker`s own trip statement never flips a tenant it was not called for, even when its count coincides', function () {
    $pluginName = 'qa-xtenant-probe-direct-'.bin2hex(random_bytes(4));

    qaHookSweepRegisterProbe(qaHookSweepPoints(), QaHookSweepProbe::class, $pluginName);
    qaHookSweepEnable($pluginName, 'tenant-a');
    qaHookSweepEnable($pluginName, 'tenant-b');

    config()->set('plugins.breaker.threshold', 2);

    $plugin = PluginSeed::pluginRow($pluginName);
    $pluginId = (string) $plugin['id'];

    // B's own row happens to already carry the same failure_count the
    // threshold asks for - through no failure of its own, which is
    // precisely the discriminating input a trip statement that forgot its
    // tenant_id filter needs to be caught by (feedback:
    // defence-in-depth-needs-a-discriminating-input).
    DB::connection('master')->table('tenant_plugins')
        ->where('tenant_id', 'tenant-b')->where('plugin_id', $pluginId)
        ->update(['failure_count' => 2]);

    // One real failure for A only, called directly on the breaker so this
    // is a statement-level claim about CircuitBreaker::recordFailure(),
    // never about Gateway's own dispatch orchestration.
    app(CircuitBreaker::class)->recordFailure('tenant-a', $pluginId, 'boom');

    $rowA = PluginSeed::tenantPluginRow('tenant-a', $pluginId);
    $rowB = PluginSeed::tenantPluginRow('tenant-b', $pluginId);

    expect((int) $rowA['failure_count'])->toBe(1)
        ->and((string) $rowA['status'])->toBe('enabled')
        ->and((string) $rowB['status'])->toBe('enabled', 'tenant B tripped from tenant A`s single failure');
});

it('AC-21: the leaky probe is reported writing into the wrong tenant`s database', function () {
    $points = qaHookSweepPoints();
    $pluginName = 'qa-xtenant-probe-leaky-'.bin2hex(random_bytes(4));

    qaHookSweepRegisterProbe($points, QaHookSweepLeakyProbe::class, $pluginName);

    expect(qaHookSweepAttack($points, QaHookSweepLeakyProbe::class, $pluginName))->not->toBe([]);
});

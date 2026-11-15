<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantQueueJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-022 Category B - the queued-job cross-tenant sweep (AC-8, AC-9
| deterministic, AC-10, AC-11, AC-24)
|--------------------------------------------------------------------------
|
| Permanent, CI-gate-enforced (docs/specs/T-022, AC-25). This file
| enumerates every `ShouldQueue` class under every package and module
| source tree and modules/host/app at run time, by reflection rather than
| by a hand-maintained list, so a second queued job is swept automatically
| the day it ships (D-5: today there is exactly one, MigrateTenantQueueJob).
|
| Two generic rules are asked of every class the scan finds, fail-closed:
|
| - AC-11: the class must carry a `tenantId`-shaped constructor-promoted
|   property, or be named on the short QA_JOB_SWEEP_TENANT_AGNOSTIC
|   allow-list with its own one-line reason (empty today).
| - AC-10: no property anywhere in its hierarchy may be named like a
|   credential, and its serialised payload must carry the tenant id and
|   none of a planted credential's needles.
|
| The one real job's ambient-worker attack (AC-9 deterministic half) is
| the one case that is NOT generic by necessity - "dispatch it, with the
| wrong tenant ambient" needs a driver that knows how to build that job -
| so it is fail-closed instead: every class the scan finds must have an
| entry in qaJobSweepAmbientDrivers(), or the sweep fails naming the class.
|
| Stated limit: the generic payload builder in qaJobSweepGenericInstance()
| can only build a class whose constructor takes string, int, bool, array
| or nullable parameters; anything else is reported as a finding rather
| than silently skipped (fail-closed, same discipline as the cache-key
| scan's rule 4).
*/

require_once __DIR__.'/../../../../packages/kernel/tests/Support/migrate-tenant-fixture.php';

/**
 * The allow-list of queued classes that are deliberately tenant-agnostic.
 * Empty today (D-5): every real job this repository ships carries a
 * tenant. Each future entry needs its own one-line reason as the array
 * value.
 *
 * @return array<string, string>
 */
function qaJobSweepTenantAgnosticAllowList(): array
{
    return [];
}

/**
 * @return list<string>
 */
function qaJobSweepDirectories(): array
{
    $packagesRoot = dirname(__DIR__, 4).'/packages';
    $modulesRoot = dirname(__DIR__, 4).'/modules';

    $directories = [dirname(__DIR__).'/../app'];

    foreach (glob($packagesRoot.'/*', GLOB_ONLYDIR) ?: [] as $package) {
        if (is_dir($package.'/src')) {
            $directories[] = $package.'/src';
        }
    }

    foreach (glob($modulesRoot.'/*', GLOB_ONLYDIR) ?: [] as $module) {
        if (is_dir($module.'/src')) {
            $directories[] = $module.'/src';
        }
    }

    sort($directories);

    return $directories;
}

/**
 * The name of the one real class declaration in a file's PHP tokens - never
 * a docblock or comment, which a plain text regex for "class" cannot tell
 * apart from a sentence containing that word.
 */
function qaJobSweepDeclaredClassName(string $contents): ?string
{
    $tokens = token_get_all($contents);

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_CLASS) {
            for ($i = $index + 1; $i < count($tokens); $i++) {
                $next = $tokens[$i];

                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    continue;
                }

                if (is_array($next) && $next[0] === T_STRING) {
                    return $next[1];
                }

                break;
            }
        }
    }

    return null;
}

/**
 * Every class under the given directories that implements `ShouldQueue`,
 * found by reflection - inheriting the interface through a base class or
 * through another interface that extends it is caught, which a text scan
 * for the literal interface name would miss.
 *
 * @param  list<string>  $directories
 * @return list<string> sorted FQCNs
 */
function qaJobSweepQueuedClasses(array $directories): array
{
    $found = [];

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
            $contents = (string) file_get_contents($file->getRealPath());

            $namespace = preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch) === 1 ? trim($nsMatch[1]) : '';

            $className = qaJobSweepDeclaredClassName($contents);

            if ($className === null) {
                continue;
            }

            $fqcn = $namespace === '' ? $className : $namespace.'\\'.$className;

            if (! class_exists($fqcn)) {
                continue;
            }

            if (is_subclass_of($fqcn, ShouldQueue::class)) {
                $found[] = $fqcn;
            }
        }
    }

    $found = array_values(array_unique($found));
    sort($found);

    return $found;
}

/**
 * W-2: every declared class this scan's own file-name/namespace parsing
 * found that does not autoload under the FQCN it derived - a finding, not
 * a silent `continue` (plan step 1). `qaJobSweepQueuedClasses()` itself
 * stays fail-closed on `ShouldQueue` membership; this is the companion
 * check that nothing it looked at was quietly dropped first.
 *
 * @param  list<string>  $directories
 * @return list<string>
 */
function qaJobSweepScanFindings(array $directories): array
{
    $findings = [];

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
            $contents = (string) file_get_contents($file->getRealPath());

            $namespace = preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch) === 1 ? trim($nsMatch[1]) : '';

            $className = qaJobSweepDeclaredClassName($contents);

            if ($className === null) {
                continue;
            }

            $fqcn = $namespace === '' ? $className : $namespace.'\\'.$className;

            if (! class_exists($fqcn)) {
                $findings[] = "{$fqcn} declared in {$file->getRealPath()} does not autoload.";
            }
        }
    }

    return $findings;
}

/**
 * Every promoted or declared property across the class and its whole
 * hierarchy, including traits.
 *
 * @return list<\ReflectionProperty>
 */
function qaJobSweepAllProperties(string $fqcn): array
{
    $reflection = new ReflectionClass($fqcn);

    return $reflection->getProperties();
}

/**
 * AC-11: does this class carry a tenant-identifying, serialisable property?
 *
 * A property named `tenantId` or ending `TenantId`, public or promoted
 * (so it survives serialize()/unserialize()).
 */
function qaJobSweepHasTenantProperty(string $fqcn): bool
{
    foreach (qaJobSweepAllProperties($fqcn) as $property) {
        $name = $property->getName();

        if ($name === 'tenantId' || str_ends_with($name, 'TenantId')) {
            return true;
        }
    }

    return false;
}

/**
 * AC-11, generic: the finding for one class, or null.
 */
function qaJobSweepTenantFinding(string $fqcn): ?string
{
    if (isset(qaJobSweepTenantAgnosticAllowList()[$fqcn])) {
        return null;
    }

    return qaJobSweepHasTenantProperty($fqcn)
        ? null
        : "{$fqcn} implements ShouldQueue but carries no tenantId-shaped property and is not on the allow-list.";
}

/**
 * AC-10, generic: a property whose name looks like a credential, anywhere
 * in the hierarchy.
 */
function qaJobSweepCredentialPropertyFinding(string $fqcn): ?string
{
    foreach (qaJobSweepAllProperties($fqcn) as $property) {
        if (preg_match('/password|passwd|secret|credential|username|dsn/i', $property->getName()) === 1) {
            return "{$fqcn}::\${$property->getName()} is a credential-shaped property on a queued job payload.";
        }
    }

    return null;
}

/**
 * Build a generic instance of a queued class from its constructor's
 * declared types, fail-closed on anything it cannot build.
 *
 * @return array{0: object|null, 1: string|null} the instance, or a finding
 */
function qaJobSweepGenericInstance(string $fqcn): array
{
    $reflection = new ReflectionClass($fqcn);
    $constructor = $reflection->getConstructor();

    if ($constructor === null) {
        return [$reflection->newInstanceWithoutConstructor(), null];
    }

    $arguments = [];

    foreach ($constructor->getParameters() as $parameter) {
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if ($type !== null && $type->allowsNull() && $parameter->isOptional()) {
            $arguments[] = null;

            continue;
        }

        $arguments[] = match ($typeName) {
            'string' => 'qa-'.$parameter->getName(),
            'int' => 1,
            'bool' => false,
            'array' => [],
            default => null,
        };

        if ($typeName !== null && ! in_array($typeName, ['string', 'int', 'bool', 'array'], true)) {
            return [null, "{$fqcn}'s constructor parameter \${$parameter->getName()} is typed [{$typeName}], which the generic payload builder cannot construct."];
        }
    }

    return [$reflection->newInstance(...$arguments), null];
}

/**
 * R-3: the plan's actual needle list - every one of these, case-insensitive,
 * anywhere in a queued job's serialised payload is a finding on its own,
 * independent of any one planted value a caller supplies.
 *
 * @var list<string>
 */
const QA_JOB_SWEEP_FORBIDDEN_NEEDLES = ['env://', 'password', 'credential', 'secret'];

/**
 * R-3: the sentinel the generic builder would have put in a `tenantId`
 * (or `*TenantId`) constructor parameter - the exact string
 * `qaJobSweepGenericInstance()` assigns a `string`-typed parameter by that
 * name - so the payload check below has something to prove a tenant id was
 * actually carried, rather than asserting on a value nothing ever wrote.
 */
function qaJobSweepTenantIdSentinel(string $fqcn): ?string
{
    $reflection = new ReflectionClass($fqcn);
    $constructor = $reflection->getConstructor();

    if ($constructor === null) {
        return null;
    }

    foreach ($constructor->getParameters() as $parameter) {
        if ($parameter->getName() === 'tenantId' || str_ends_with($parameter->getName(), 'TenantId')) {
            return 'qa-'.$parameter->getName();
        }
    }

    return null;
}

/**
 * AC-10, generic: build a generic instance, serialise it, and check that
 * the tenant id is actually carried (R-3's first clause) and that neither
 * the plan's forbidden needles (QA_JOB_SWEEP_FORBIDDEN_NEEDLES) nor any
 * caller-supplied planted needle appears anywhere in the payload.
 *
 * @param  list<string>  $needles  credential values that must not appear
 */
function qaJobSweepPayloadFinding(string $fqcn, array $needles = []): ?string
{
    [$instance, $finding] = qaJobSweepGenericInstance($fqcn);

    if ($finding !== null) {
        return $finding;
    }

    $payload = serialize($instance);

    $sentinel = qaJobSweepTenantIdSentinel($fqcn);

    if ($sentinel !== null && ! str_contains($payload, $sentinel)) {
        return "{$fqcn}'s serialised payload does not carry its tenant id.";
    }

    foreach (QA_JOB_SWEEP_FORBIDDEN_NEEDLES as $forbidden) {
        if (stripos($payload, $forbidden) !== false) {
            return "{$fqcn}'s serialised payload contains the forbidden string [{$forbidden}].";
        }
    }

    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($payload, $needle)) {
            return "{$fqcn}'s serialised payload contains a planted credential needle.";
        }
    }

    return null;
}

/**
 * The fail-closed driver map for AC-9's deterministic ambient-worker attack.
 *
 * One entry per real job the scan finds: a closure that dispatches it for
 * tenant A through the sync queue connection.
 *
 * @param  array{runId: string, jobId: string, tenantA: string, worker: string}  $ctx
 * @return array<string, \Closure(string): void>
 */
function qaJobSweepAmbientDrivers(array $ctx): array
{
    return [
        MigrateTenantQueueJob::class => function (string $ambientTenant) use ($ctx): void {
            $job = MigrateTenantQueueJob::forJob(
                $ctx['runId'],
                $ctx['jobId'],
                $ctx['tenantA'],
                $ctx['worker'],
                runFixtureSet(),
                'schema'
            );

            Bus::dispatch($job);
        },
    ];
}

/**
 * AC-9 deterministic, W-1: scenario R, the recycled worker - the ambient
 * tenant is entered and the container's scoped bindings are dropped with
 * no `leave()`, exactly what a worker recycled between jobs does, before
 * `$driver` runs. Returns a finding for every migration call this attack
 * causes to land against the ambient tenant's own database - proof the
 * job trusted the connection it was handed rather than resolving its own,
 * driven through the same attack both the positive case (AC-9, with the
 * real job's driver) and the negative control (with a trusting double)
 * use.
 *
 * @param  \Closure(string): void  $driver
 * @return list<string>
 */
function qaJobSweepAmbientAttack(\Closure $driver, string $ambientTenant): array
{
    tenancy()->enter($ambientTenant);
    app()->forgetScopedInstances();

    $driver($ambientTenant);

    $applier = migrateFixtureApplier();
    $ambientDatabase = DB::connection('master')->table('tenant_databases')->where('tenant_id', $ambientTenant)->value('database_name');

    $findings = [];

    foreach ($applier->calls as $call) {
        if ($call['database'] === $ambientDatabase) {
            $findings[] = "a migration call landed against the ambient tenant's database [{$ambientTenant}]";
        }
    }

    return $findings;
}

if (! class_exists('QaJobSweepAmbientTrustingJob', false)) {
    /**
     * W-1: a stand-in for a job that applies to whatever connection is
     * currently configured rather than entering its own tenant through
     * `Tenancy::enter()` - exactly the bug AC-9 exists to catch. It lives
     * only in this test file, never under a scanned `src/` tree, so it is
     * a test-file double and not a production mutation.
     */
    final class QaJobSweepAmbientTrustingJob implements ShouldQueue
    {
        use Queueable;

        public function handle(): void
        {
            migrateFixtureApplier()->apply(config('tenancy.connection', 'tenant'), runFixtureSet(), 1);
        }
    }
}

beforeEach(function () {
    migrateFixtureBoot();
});

afterEach(function () {
    migrateFixtureTearDown();
});

it('AC-24: every scanned directory actually exists', function () {
    foreach (qaJobSweepDirectories() as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }

    expect(qaJobSweepDirectories())->not->toBe([]);
});

it('AC-8: the only ShouldQueue class in the repository is MigrateTenantQueueJob', function () {
    expect(qaJobSweepScanFindings(qaJobSweepDirectories()))->toBe([]);

    $found = qaJobSweepQueuedClasses(qaJobSweepDirectories());

    expect($found)->toBe([MigrateTenantQueueJob::class]);

    fwrite(STDOUT, PHP_EOL.'AC-8 ShouldQueue classes: '.implode(', ', $found).PHP_EOL);
});

it('AC-11: every found class carries a tenant-identifying property or is allow-listed, reported fail-closed', function () {
    foreach (qaJobSweepQueuedClasses(qaJobSweepDirectories()) as $fqcn) {
        expect(qaJobSweepTenantFinding($fqcn))->toBeNull();
    }

    $directory = sys_get_temp_dir().'/qa_job_sweep_'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);

    try {
        $class = 'QaJobSweepPlantedNoTenant'.bin2hex(random_bytes(4));

        File::put($directory.'/Planted.php', "<?php\n"
            ."final class {$class} implements \\Illuminate\\Contracts\\Queue\\ShouldQueue {\n"
            ."    use \\Illuminate\\Bus\\Queueable;\n"
            ."    public function __construct(public readonly string \$runId) {}\n"
            .'}'.PHP_EOL);

        require_once $directory.'/Planted.php';

        $found = qaJobSweepQueuedClasses([$directory]);

        expect($found)->toBe([$class]);
        expect(qaJobSweepTenantFinding($class))->not->toBeNull();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-10: no property looks like a credential, and the serialised payload carries no planted needle, reported fail-closed', function () {
    foreach (qaJobSweepQueuedClasses(qaJobSweepDirectories()) as $fqcn) {
        expect(qaJobSweepCredentialPropertyFinding($fqcn))->toBeNull();
        expect(qaJobSweepPayloadFinding($fqcn, [migrateFixturePassword()]))->toBeNull();
    }

    $directory = sys_get_temp_dir().'/qa_job_sweep_'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);

    try {
        $class = 'QaJobSweepPlantedCredential'.bin2hex(random_bytes(4));

        File::put($directory.'/Planted.php', "<?php\n"
            ."final class {$class} implements \\Illuminate\\Contracts\\Queue\\ShouldQueue {\n"
            ."    use \\Illuminate\\Bus\\Queueable;\n"
            ."    public function __construct(public readonly string \$tenantId, public readonly string \$password = 'x') {}\n"
            .'}'.PHP_EOL);

        require_once $directory.'/Planted.php';

        expect(qaJobSweepCredentialPropertyFinding($class))->not->toBeNull();

        // R-3: the payload function itself, not just the separate
        // property-name rule, must also report this plant - its own
        // credential-shaped property name (`password`) is serialised
        // verbatim as a struct key, so qaJobSweepPayloadFinding() must
        // catch it through QA_JOB_SWEEP_FORBIDDEN_NEEDLES even with no
        // caller-supplied needle at all.
        expect(qaJobSweepPayloadFinding($class))->not->toBeNull();

        // The positive plant: tenantId and no credential, no finding.
        $positive = 'QaJobSweepPlantedTenantOnly'.bin2hex(random_bytes(4));

        File::put($directory.'/PlantedPositive.php', "<?php\n"
            ."final class {$positive} implements \\Illuminate\\Contracts\\Queue\\ShouldQueue {\n"
            ."    use \\Illuminate\\Bus\\Queueable;\n"
            ."    public function __construct(public readonly string \$tenantId) {}\n"
            .'}'.PHP_EOL);

        require_once $directory.'/PlantedPositive.php';

        expect(qaJobSweepTenantFinding($positive))->toBeNull();
        expect(qaJobSweepCredentialPropertyFinding($positive))->toBeNull();
        expect(qaJobSweepPayloadFinding($positive))->toBeNull();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-9 deterministic: a recycled worker still resolves the job`s own tenant, never the one left ambient', function () {
    $server = migrateFixtureServer();
    $tenantA = migrateFixtureTenant('job-sweep-a', $server);
    $tenantB = migrateFixtureTenant('job-sweep-b', $server);
    $runId = migrateFixtureRun();
    $jobId = 'JOB0000000000000000SWEEPR';
    $toVersion = runFixtureBaselineVersion();

    migrateFixtureClaimedJob($runId, $jobId, $tenantA, $server, $toVersion, 'worker-sweep-r', ['id' => $jobId]);

    $drivers = qaJobSweepAmbientDrivers(['runId' => $runId, 'jobId' => $jobId, 'tenantA' => $tenantA, 'worker' => 'worker-sweep-r']);

    foreach (qaJobSweepQueuedClasses(qaJobSweepDirectories()) as $fqcn) {
        expect(isset($drivers[$fqcn]))->toBeTrue("No AC-9 ambient driver is registered for [{$fqcn}].");
    }

    // Scenario R, the recycled worker: tenant B is left ambient and the
    // container scope is dropped with no leave() - exactly what a worker
    // recycled between jobs does - and then the job for A is dispatched.
    $findings = qaJobSweepAmbientAttack($drivers[MigrateTenantQueueJob::class], $tenantB);

    $jobRow = migrateFixtureJobRow($jobId);
    $mappingB = migrateFixtureMappingRow($tenantB);
    $mappingA = migrateFixtureMappingRow($tenantA);

    expect($findings)->toBe([]);
    expect((string) $jobRow['status'])->toBe('done');
    expect((int) $mappingA['schema_version'])->toBeGreaterThan(0);
    expect((int) $mappingB['schema_version'])->toBe(0);
});

it('AC-9 deterministic: a leaked scope refuses rather than silently migrating the wrong tenant', function () {
    $server = migrateFixtureServer();
    $tenantA = migrateFixtureTenant('job-sweep-la', $server);
    $tenantB = migrateFixtureTenant('job-sweep-lb', $server);
    $runId = migrateFixtureRun();
    $jobId = 'JOB0000000000000000SWEEPL';
    $toVersion = runFixtureBaselineVersion();

    migrateFixtureClaimedJob($runId, $jobId, $tenantA, $server, $toVersion, 'worker-sweep-l', ['id' => $jobId]);

    $drivers = qaJobSweepAmbientDrivers(['runId' => $runId, 'jobId' => $jobId, 'tenantA' => $tenantA, 'worker' => 'worker-sweep-l']);

    // Scenario L, the leaked scope: tenant B is entered with no reset at
    // all - the same Tenancy scope stays resolved - and then the job for A
    // is dispatched.
    tenancy()->enter($tenantB);

    try {
        ($drivers[MigrateTenantQueueJob::class])($tenantB);
    } finally {
        tenancy()->leave();
    }

    $applier = migrateFixtureApplier();

    expect($applier->calls)->toBe([]);
    expect((string) migrateFixtureJobRow($jobId)['status'])->toBe('failed');
});

it('negative control, W-1: a job that trusts the ambient connection is reported by the same scenario-R attack', function () {
    $server = migrateFixtureServer();
    $tenantB = migrateFixtureTenant('job-sweep-neg-b', $server);

    // QaJobSweepAmbientTrustingJob applies to whatever connection is
    // currently configured rather than entering its own tenant - exactly
    // the bug AC-9 exists to catch - driven through qaJobSweepAmbientAttack(),
    // the exact function the positive AC-9 case above uses. A finding here
    // is the proof that function actually detects this failure mode, not
    // just that it stays quiet on the real, correctly-isolated job.
    $findings = qaJobSweepAmbientAttack(
        function (string $ambientTenant): void {
            (new QaJobSweepAmbientTrustingJob)->handle();
        },
        $tenantB
    );

    expect($findings)->not->toBe([], 'the ambient-trusting double was not reported as a leak');

    tenancy()->leave();
});

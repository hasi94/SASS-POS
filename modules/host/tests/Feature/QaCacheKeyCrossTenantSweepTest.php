<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\ApiActor;
use App\Support\Api\ApiLimits;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Tenancy\CachingTenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\EvictsTenantMappings;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\TenantDatabase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-022 Category C - the cache-key cross-tenant sweep (AC-12 to AC-16,
| AC-24)
|--------------------------------------------------------------------------
|
| Permanent, CI-gate-enforced (docs/specs/T-022, AC-25). A source scan finds
| every cache/rate-limiter key construction site under every package and
| module source tree and modules/host/app, at run time, never from a
| hand-maintained list (plan step 4). Every found key-building expression
| is required to embed a tenant, terminal or IP token, or to be on the
| short QA_CACHE_SWEEP_TENANT_AGNOSTIC allow-list with its own reason
| (plan N-2). Two runtime halves (AC-15, AC-16) then prove the same claim
| against live behaviour, not merely against the source text.
|
| Stated limits: the token-name heuristic trusts that a variable merely
| *named* like a tenant id holds one, which is why AC-15/AC-16 exist as a
| runtime check beside the text scan; and `PluginSdk\Runtime\ManifestCache`,
| an in-process array memo keyed by a global plugin_version_id, is outside
| this scan's patterns by design (plan N-1).
*/

const QA_CACHE_SWEEP_SITES = [
    'packages/kernel/src/Tenancy/CachingTenantDatabaseRepository.php',
    'packages/platform/src/Support/CachingEntitlementSource.php',
    'packages/auth/src/Support/ActivationThrottle.php',
    'modules/host/app/Http/Middleware/ThrottleApi.php',
];

/**
 * file (repo-relative) => [builderFile (repo-relative), names to check in
 * that file]. A delegated site (rule 3) is OK only when every one of these
 * names passes rule 1 in its own file.
 *
 * @var array<string, array{file: string, names: list<string>}>
 */
const QA_CACHE_SWEEP_BUILDERS = [
    'packages/auth/src/Support/ActivationThrottle.php' => [
        'file' => 'packages/auth/src/Support/ActivationThrottle.php',
        'names' => ['tenantKey', 'addressKey'],
    ],
    'modules/host/app/Http/Middleware/ThrottleApi.php' => [
        'file' => 'modules/host/app/Http/Requests/ApiActor.php',
        'names' => ['tenantBucket', 'userBucket', 'terminalBucket', 'ipBucket'],
    ],
];

/**
 * [repo-relative file, exact expression text, reason]. One entry on day
 * one (N-2): a platform token carries no tenant by construction.
 *
 * @var list<array{0: string, 1: string, 2: string}>
 */
const QA_CACHE_SWEEP_TENANT_AGNOSTIC = [
    ['modules/host/app/Http/Requests/ApiActor.php', 'u:platform:{$platformUserId}', 'a platform token carries no tenant'],
];

const QA_CACHE_SWEEP_KEY_METHODS = [
    'get', 'put', 'add', 'forever', 'forget', 'has', 'missing', 'pull', 'remember', 'rememberForever',
    'increment', 'decrement', 'lock', 'hit', 'attempt', 'tooManyAttempts', 'attempts', 'availableIn',
    'remaining', 'resetAttempts', 'clear', 'many', 'putMany',
];

const QA_CACHE_SWEEP_RECEIVER_TYPES = [
    'Illuminate\Contracts\Cache\Repository',
    'Illuminate\Cache\Repository',
    'Illuminate\Cache\RateLimiter',
    'Illuminate\Contracts\Cache\Store',
    'Illuminate\Contracts\Cache\LockProvider',
];

/**
 * @return list<string>
 */
function qaCacheSweepDirectories(): array
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
 * `$path` relative to the repository root, with `/` separators regardless
 * of platform.
 */
function qaCacheSweepRepoRelativePath(string $path): string
{
    $root = str_replace('\\', '/', dirname(__DIR__, 4)).'/';
    $normalised = str_replace('\\', '/', $path);

    return str_starts_with($normalised, $root) ? substr($normalised, strlen($root)) : $normalised;
}

/**
 * @param  list<string>  $directories
 * @return array<string, string> real path => contents
 */
function qaCacheSweepFiles(array $directories): array
{
    $files = [];

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
            $files[$file->getRealPath()] = (string) file_get_contents($file->getRealPath());
        }
    }

    return $files;
}

/**
 * `use X;` / `use X as Y;` imports, as short-name/alias => FQCN.
 *
 * @return array<string, string>
 */
function qaCacheSweepImports(string $contents): array
{
    $imports = [];

    preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

    foreach ($matches[1] as $import) {
        $import = trim($import);

        if (preg_match('/^(.+?)\s+as\s+(\w+)$/', $import, $aliasMatch) === 1) {
            $imports[$aliasMatch[2]] = trim($aliasMatch[1], '\\');

            continue;
        }

        $parts = explode('\\', trim($import, '\\'));
        $imports[end($parts)] = trim($import, '\\');
    }

    return $imports;
}

/**
 * Every local variable/property name this file declares as a cache-ish
 * receiver, through a constructor-promoted or declared property typed as
 * one of QA_CACHE_SWEEP_RECEIVER_TYPES (resolved through this file's own
 * imports).
 *
 * @return list<string>
 */
function qaCacheSweepTypedReceivers(string $contents): array
{
    $imports = qaCacheSweepImports($contents);
    $receivers = [];

    // Promoted and plain property declarations: `private readonly ?Type $name`
    if (preg_match_all('/(?:private|protected|public)\s+(?:readonly\s+)?\??([A-Za-z_][\w]*)\s+\$(\w+)/', $contents, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $shortType = $match[1];
            $fqcn = $imports[$shortType] ?? $shortType;

            if (in_array($fqcn, QA_CACHE_SWEEP_RECEIVER_TYPES, true)) {
                $receivers[] = $match[2];
            }
        }
    }

    return array_values(array_unique($receivers));
}

/**
 * One key-taking call's raw first-argument text, with the method name and
 * the character offset it was found at.
 *
 * @param  list<string>  $receivers
 * @return list<array{method: string, argument: string, offset: int}>
 */
function qaCacheSweepCallSites(string $contents, array $receivers): array
{
    $methods = implode('|', array_map('preg_quote', QA_CACHE_SWEEP_KEY_METHODS));
    $calls = [];

    $receiverPattern = $receivers === [] ? null : '(?:\$this->(?:'.implode('|', array_map('preg_quote', $receivers)).')|\$(?:'.implode('|', array_map('preg_quote', $receivers)).'))';

    $patterns = [];

    if ($receiverPattern !== null) {
        $patterns[] = $receiverPattern.'\s*->\s*('.$methods.')\s*\(';
    }

    $patterns[] = '\b(?:Cache|RateLimiter)::\s*('.$methods.')\s*\(';
    $patterns[] = '\bcache\(\)\s*->\s*('.$methods.')\s*\(';

    foreach ($patterns as $pattern) {
        if (preg_match_all('/'.$pattern.'/', $contents, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches[0] as $index => $wholeMatch) {
            $method = $matches[1][$index][0];
            $openParenOffset = $wholeMatch[1] + strlen($wholeMatch[0]) - 1;
            $argument = qaCacheSweepFirstArgument($contents, $openParenOffset);

            $calls[] = ['method' => $method, 'argument' => $argument, 'offset' => $wholeMatch[1]];
        }
    }

    return $calls;
}

/**
 * The first argument's raw text, read with a balanced-parenthesis reader
 * starting just after the opening `(`.
 */
function qaCacheSweepFirstArgument(string $contents, int $openParenOffset): string
{
    $depth = 0;
    $start = $openParenOffset + 1;
    $i = $start;
    $len = strlen($contents);
    $argument = '';

    while ($i < $len) {
        $char = $contents[$i];

        if ($char === '(') {
            $depth++;
        } elseif ($char === ')') {
            if ($depth === 0) {
                break;
            }

            $depth--;
        } elseif ($char === ',' && $depth === 0) {
            break;
        }

        $argument .= $char;
        $i++;
    }

    return trim($argument);
}

/**
 * Rule 1: does this expression embed a tenant/terminal/IP/address token,
 * as a bare variable, inside `{$x}` interpolation, or as an argument to
 * `hash(...)`?
 */
function qaCacheSweepHasToken(string $expression): bool
{
    return preg_match('/\$(\w*(?:tenant|terminal|address)\w*|ip)\b/i', $expression) === 1;
}

/**
 * Whether an expression carries any variable or property fetch at all -
 * rule 3 versus rule 4's "nothing to delegate to".
 */
function qaCacheSweepHasAnyVariable(string $expression): bool
{
    return preg_match('/\$\w+/', $expression) === 1;
}

/**
 * Resolve a `self::m(...)`/`$this->m(...)`/`static::m(...)` expression to
 * that method's own `return` statement, in the same file (rule 2).
 */
function qaCacheSweepResolveMethodCall(string $contents, string $expression): ?string
{
    if (preg_match('/^(?:self::|static::|\$this->)(\w+)\s*\(/', $expression, $match) !== 1) {
        return null;
    }

    $method = $match[1];

    if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)[^{]*\{(.*?)\n\s*\}/s', $contents, $bodyMatch) !== 1) {
        return null;
    }

    if (preg_match('/return\s+(.+?);/s', $bodyMatch[1], $returnMatch) !== 1) {
        return null;
    }

    return trim($returnMatch[1]);
}

/**
 * The findings for one site's file, given the real repository tree (or a
 * planted stand-in directory for the negative controls).
 *
 * @return list<string>
 */
function qaCacheSweepKeyFindings(string $repoRelativePath, string $contents): array
{
    $receivers = qaCacheSweepTypedReceivers($contents);
    $calls = qaCacheSweepCallSites($contents, $receivers);

    if ($calls === []) {
        return [];
    }

    $findings = [];
    $delegated = [];

    foreach ($calls as $call) {
        $argument = $call['argument'];

        // Rule 2 takes priority over rule 1 for a self-method-call shaped
        // argument: `$this->keyFor($tenantId)` must never pass merely
        // because the call's own argument list mentions a token variable -
        // the question is what keyFor() itself returns, not what it was
        // handed (M-4's own mutation: the call site is unchanged, only the
        // method body stops using its parameter).
        $resolved = qaCacheSweepResolveMethodCall($contents, $argument);

        if ($resolved !== null) {
            if (qaCacheSweepHasToken($resolved)) {
                continue;
            }

            $findings[] = "{$repoRelativePath}: [{$call['method']}({$argument})] resolves to [{$resolved}], which carries no tenant/terminal/IP token.";

            continue;
        }

        if (qaCacheSweepHasToken($argument)) {
            continue;
        }

        if (qaCacheSweepIsAllowListed($repoRelativePath, $argument)) {
            continue;
        }

        if (qaCacheSweepHasAnyVariable($argument)) {
            $delegated[] = $call;

            continue;
        }

        $findings[] = "{$repoRelativePath}: [{$call['method']}({$argument})] carries no tenant/terminal/IP token and nothing to delegate to.";
    }

    if ($delegated !== []) {
        $builder = QA_CACHE_SWEEP_BUILDERS[$repoRelativePath] ?? null;

        if ($builder === null) {
            foreach ($delegated as $call) {
                $findings[] = "{$repoRelativePath}: [{$call['method']}({$call['argument']})] is delegated, and no builder is registered for this site.";
            }
        } elseif (! qaCacheSweepBuilderPasses($builder)) {
            foreach ($delegated as $call) {
                $findings[] = "{$repoRelativePath}: [{$call['method']}({$call['argument']})] delegates to a builder that does not embed a token.";
            }
        }
    }

    return $findings;
}

/**
 * @param  array{file: string, names: list<string>}  $builder
 */
function qaCacheSweepBuilderPasses(array $builder): bool
{
    $path = dirname(__DIR__, 4).'/'.$builder['file'];

    if (! is_file($path)) {
        return false;
    }

    $contents = (string) file_get_contents($path);

    foreach ($builder['names'] as $name) {
        $expressions = qaCacheSweepBuilderExpressions($contents, $name);

        if ($expressions === []) {
            return false;
        }

        foreach ($expressions as $expression) {
            if ($expression === 'null' || qaCacheSweepHasToken($expression) || qaCacheSweepIsAllowListed($builder['file'], $expression)) {
                continue;
            }

            return false;
        }
    }

    return true;
}

/**
 * Every `function NAME(...): ... { ... return EXPR; ... }` method's
 * return expression, and every `NAME: EXPR` named-constructor-argument's
 * expression - a builder may build the same name differently per branch
 * (ApiActor's four `return new self(...)` cases each set a different
 * subset of its buckets, leaving the rest `null`), so every occurrence is
 * checked and a bare `null` is accepted as "not applicable here" rather
 * than as a missing token.
 *
 * @return list<string>
 */
function qaCacheSweepBuilderExpressions(string $contents, string $name): array
{
    $expressions = [];

    if (preg_match('/function\s+'.preg_quote($name, '/').'\s*\([^)]*\)[^{]*\{(.*?)\n\s*\}/s', $contents, $bodyMatch) === 1
        && preg_match('/return\s+(.+?);/s', $bodyMatch[1], $returnMatch) === 1) {
        $expressions[] = trim($returnMatch[1]);
    }

    if (preg_match_all('/\b'.preg_quote($name, '/').'\s*:\s*(.+?),?\n/', $contents, $namedMatches) > 0) {
        foreach ($namedMatches[1] as $namedMatch) {
            $expressions[] = trim(rtrim(trim($namedMatch), ','));
        }
    }

    return $expressions;
}

function qaCacheSweepIsAllowListed(string $repoRelativePath, string $expression): bool
{
    foreach (QA_CACHE_SWEEP_TENANT_AGNOSTIC as [$file, $text]) {
        if ($file === $repoRelativePath && trim($expression, '\'"') === trim($text, '\'"')) {
            return true;
        }
    }

    return false;
}

/**
 * Every allow-list and builder entry must still name text that actually
 * exists in its file - a liveness rule so a stale entry cannot silently
 * keep passing.
 *
 * @return list<string>
 */
function qaCacheSweepStaleEntries(): array
{
    $stale = [];

    foreach (QA_CACHE_SWEEP_TENANT_AGNOSTIC as $entry) {
        [$file, $text, $reason] = $entry;
        $path = dirname(__DIR__, 4).'/'.$file;

        if (! is_file($path) || ! str_contains((string) file_get_contents($path), $text)) {
            $stale[] = "allow-list entry [{$file}: {$text}] no longer matches that file.";
        }

        if (trim((string) $reason) === '') {
            $stale[] = "allow-list entry [{$file}: {$text}] carries no reason.";
        }
    }

    foreach (QA_CACHE_SWEEP_BUILDERS as $site => $builder) {
        if (! is_file(dirname(__DIR__, 4).'/'.$builder['file'])) {
            $stale[] = "builder entry for [{$site}] names a file that does not exist.";
        }
    }

    return $stale;
}

/**
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaCacheSweepSiteList(array $files): array
{
    $sites = [];

    foreach ($files as $path => $contents) {
        $receivers = qaCacheSweepTypedReceivers($contents);

        if (qaCacheSweepCallSites($contents, $receivers) !== []) {
            $sites[] = qaCacheSweepRepoRelativePath($path);
        }
    }

    sort($sites);

    return array_values(array_unique($sites));
}

it('AC-24: every scanned directory actually exists', function () {
    foreach (qaCacheSweepDirectories() as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }

    expect(qaCacheSweepDirectories())->not->toBe([]);
});

it('AC-12: the scan finds exactly the four known cache-key construction sites', function () {
    $files = qaCacheSweepFiles(qaCacheSweepDirectories());
    $sites = qaCacheSweepSiteList($files);

    sort($sites);
    $expected = QA_CACHE_SWEEP_SITES;
    sort($expected);

    expect($sites)->toBe($expected);

    fwrite(STDOUT, PHP_EOL.'AC-12 cache-key sites: '.implode(', ', $sites).PHP_EOL);
});

it('AC-13: every found site`s key embeds a tenant/terminal/IP token or is allow-listed, fail-closed, and the allow-list/builders are live', function () {
    expect(qaCacheSweepStaleEntries())->toBe([]);

    $files = qaCacheSweepFiles(qaCacheSweepDirectories());
    $findings = [];

    foreach (QA_CACHE_SWEEP_SITES as $site) {
        $path = dirname(__DIR__, 4).'/'.$site;
        $contents = (string) file_get_contents($path);

        $siteFindings = qaCacheSweepKeyFindings($site, $contents);
        $findings = array_merge($findings, $siteFindings);
    }

    expect($findings)->toBe([], implode('; ', $findings));
});

it('AC-14: planted files are reported, and removing the plant returns the sweep to green', function () {
    $directory = sys_get_temp_dir().'/qa_cache_sweep_'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);

    try {
        // (a) a typed CacheRepository with a global key: a finding.
        File::put($directory.'/PlantedA.php', <<<'PHP'
<?php
use Illuminate\Contracts\Cache\Repository as CacheRepository;
final class QaCacheSweepPlantedA
{
    public function __construct(private readonly CacheRepository $cache) {}
    public function hit(): void { $this->cache->put('global:thing', 1); }
}
PHP);
        expect(qaCacheSweepKeyFindings('PlantedA.php', (string) file_get_contents($directory.'/PlantedA.php')))->not->toBe([]);

        // (b) a tenant-scoped key: detected, no finding.
        File::put($directory.'/PlantedB.php', <<<'PHP'
<?php
use Illuminate\Contracts\Cache\Repository as CacheRepository;
final class QaCacheSweepPlantedB
{
    public function __construct(private readonly CacheRepository $cache) {}
    public function hit(string $tenantId): void { $this->cache->put('tenant:'.$tenantId, 1); }
}
PHP);
        $bContents = (string) file_get_contents($directory.'/PlantedB.php');
        expect(qaCacheSweepSiteList([$directory.'/PlantedB.php' => $bContents]))->toHaveCount(1);
        expect(qaCacheSweepKeyFindings('PlantedB.php', $bContents))->toBe([]);

        // (c) a delegated read with no builder entry: a finding.
        File::put($directory.'/PlantedC.php', <<<'PHP'
<?php
use Illuminate\Contracts\Cache\Repository as CacheRepository;
final class QaCacheSweepPlantedC
{
    public function __construct(private readonly CacheRepository $cache) {}
    public function read(string $key): mixed { return $this->cache->get($key); }
}
PHP);
        expect(qaCacheSweepKeyFindings('PlantedC.php', (string) file_get_contents($directory.'/PlantedC.php')))->not->toBe([]);

        // (d) through the Cache facade: a finding.
        File::put($directory.'/PlantedD.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
final class QaCacheSweepPlantedD
{
    public function hit(string $userId): void { Cache::put('x:'.$userId, 1); }
}
PHP);
        expect(qaCacheSweepKeyFindings('PlantedD.php', (string) file_get_contents($directory.'/PlantedD.php')))->not->toBe([]);

        // (e) a keyFor() returning an untokened literal: a finding through rule 2.
        File::put($directory.'/PlantedE.php', <<<'PHP'
<?php
use Illuminate\Contracts\Cache\Repository as CacheRepository;
final class QaCacheSweepPlantedE
{
    public function __construct(private readonly CacheRepository $cache) {}
    public function hit(string $id): void { $this->cache->put($this->keyFor($id), 1); }
    private function keyFor(string $id): string { return 'k:'.$id; }
}
PHP);
        expect(qaCacheSweepKeyFindings('PlantedE.php', (string) file_get_contents($directory.'/PlantedE.php')))->not->toBe([]);

        // (f) a planted builder declaration whose name does not embed a token.
        $builder = ['file' => 'PlantedF.php', 'names' => ['fooBucket']];
        File::put($directory.'/PlantedF.php', <<<'PHP'
<?php
final class QaCacheSweepPlantedFBuilder
{
    public static function build(string $userId): array
    {
        return ['fooBucket' => "f:{$userId}"];
    }
}
PHP);
        expect(qaCacheSweepBuilderPasses($builder))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }

    // Green again, with still exactly four sites, once the plants are gone.
    $files = qaCacheSweepFiles(qaCacheSweepDirectories());
    expect(qaCacheSweepSiteList($files))->toHaveCount(4);
});

/*
|--------------------------------------------------------------------------
| AC-15 - the mapping cache's runtime isolation
|--------------------------------------------------------------------------
*/

/**
 * A minimal TenantDatabaseRepository stand-in that returns one marker
 * record per tenant id, and counts how many times it was actually asked.
 */
final class QaCacheSweepCountingMappingSource implements TenantDatabaseRepository
{
    public int $reads = 0;

    public function find(string $tenantId): ?TenantDatabase
    {
        $this->reads++;

        if ($tenantId === '') {
            return null;
        }

        return new TenantDatabase(
            tenantId: $tenantId,
            driver: 'sqlite',
            host: '127.0.0.1',
            port: 3306,
            database: 'marker-'.$tenantId,
            username: 'u',
            credentialRef: 'env://QA',
            status: 'active',
            schemaVersion: 1,
            credentialVersion: 1,
        );
    }
}

/**
 * A double that stores every entry under one constant key - the AC-15
 * negative control.
 */
final class QaCacheSweepConstantKeyRepository implements EvictsTenantMappings, TenantDatabaseRepository
{
    public function __construct(
        private readonly TenantDatabaseRepository $inner,
        private readonly \Illuminate\Contracts\Cache\Repository $cache,
    ) {}

    public function find(string $tenantId): ?TenantDatabase
    {
        $entry = $this->cache->get('constant-key');

        if ($entry instanceof TenantDatabase) {
            return $entry;
        }

        $record = $this->inner->find($tenantId);

        if ($record !== null) {
            $this->cache->put('constant-key', $record, 300);
        }

        return $record;
    }

    public function evict(string $tenantId): void
    {
        $this->cache->forget('constant-key');
    }
}

/**
 * @param  \Closure(TenantDatabaseRepository, \Illuminate\Contracts\Cache\Repository, QaCacheSweepCountingMappingSource): TenantDatabaseRepository  $factory
 * @return list<string>
 */
function qaCacheSweepMappingProbe(\Closure $factory): array
{
    $findings = [];
    $inner = new QaCacheSweepCountingMappingSource;
    $cache = new Repository(new ArrayStore);
    $repository = $factory($inner, $cache, $inner);

    $a = $repository->find('tenant-a');
    $b = $repository->find('tenant-b');

    if ($a === null || $a->database !== 'marker-tenant-a') {
        $findings[] = 'tenant-a`s own read did not return tenant-a`s own record';
    }

    if ($b === null || $b->database !== 'marker-tenant-b') {
        $findings[] = 'tenant-b entered tenant-a`s database (or no record at all)';
    }

    $readsAfterBoth = $inner->reads;

    $again = $repository->find('tenant-a');

    if ($again === null || $again->database !== 'marker-tenant-a' || $inner->reads !== $readsAfterBoth) {
        $findings[] = 'tenant-a`s second read was not served from cache, or returned the wrong tenant';
    }

    if ($repository instanceof EvictsTenantMappings) {
        $repository->evict('tenant-a');

        $afterEvictB = $repository->find('tenant-b');

        if ($afterEvictB === null || $afterEvictB->database !== 'marker-tenant-b' || $inner->reads !== $readsAfterBoth) {
            $findings[] = 'evicting tenant-a`s entry disturbed tenant-b`s cached entry';
        }
    }

    return $findings;
}

it('AC-15: CachingTenantDatabaseRepository stores and reads under its own key only, and evict() for one tenant leaves the other untouched', function () {
    $findings = qaCacheSweepMappingProbe(
        fn ($inner, $cache) => new CachingTenantDatabaseRepository($inner, $cache, 300)
    );

    expect($findings)->toBe([]);

    $leaky = qaCacheSweepMappingProbe(
        fn ($inner, $cache) => new QaCacheSweepConstantKeyRepository($inner, $cache)
    );

    expect($leaky)->not->toBe([]);
});

/*
|--------------------------------------------------------------------------
| AC-16 - the rate limiter's runtime isolation
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/../Support/organization-http-fixture.php';

/**
 * A limiter double that collapses every bucket key to one constant - the
 * AC-16 negative control. `Illuminate\Cache\RateLimiter` is not `final`.
 */
final class QaCacheSweepCollapsingLimiter extends RateLimiter
{
    public function tooManyAttempts($key, $maxAttempts)
    {
        return parent::tooManyAttempts('collapsed', $maxAttempts);
    }

    public function hit($key, $decaySeconds = 60)
    {
        return parent::hit('collapsed', $decaySeconds);
    }

    public function availableIn($key)
    {
        return parent::availableIn('collapsed');
    }
}

/**
 * The public properties of ApiActor ending `Bucket`, reflected rather than
 * hand-listed, so a new bucket is picked up (and must have a driver) with
 * no edit here.
 *
 * @return list<string>
 */
function qaCacheSweepBucketProperties(): array
{
    $reflection = new \ReflectionClass(ApiActor::class);
    $names = [];

    foreach ($reflection->getProperties() as $property) {
        if (str_ends_with($property->getName(), 'Bucket')) {
            $names[] = $property->getName();
        }
    }

    sort($names);

    return $names;
}

/**
 * @return array<string, \Closure(): list<string>>
 */
function qaCacheSweepBucketDrivers(): array
{
    return [
        'tenantBucket' => function (): array {
            orgHttpBoot();

            try {
                config()->set('api.limits', ['tenant' => 1, 'user' => 100000, 'terminal' => 100000, 'unauthenticated' => 100000]);
                app()->forgetInstance(ApiLimits::class);

                $worldA = orgHttpWorld('a');
                $worldB = orgHttpWorld('b');
                $tokenA = orgHttpToken($worldA, $worldA['owner']);
                $tokenB = orgHttpToken($worldB, $worldB['owner']);

                orgHttpCall('GET', '/api/v1/organization/branches', $tokenA);
                $exhausted = orgHttpCall('GET', '/api/v1/organization/branches', $tokenA);
                $other = orgHttpCall('GET', '/api/v1/organization/branches', $tokenB);

                $findings = [];

                if ($exhausted->getStatusCode() !== 429) {
                    $findings[] = 'tenant A was not throttled after exhausting its own bucket';
                }

                if ($other->getStatusCode() === 429) {
                    $findings[] = 'tenant B was throttled by tenant A`s bucket';
                }

                return $findings;
            } finally {
                AccessTenantFixture::forget();
            }
        },
        'userBucket' => function (): array {
            orgHttpBoot();

            try {
                config()->set('api.limits', ['tenant' => 100000, 'user' => 1, 'terminal' => 100000, 'unauthenticated' => 100000]);
                app()->forgetInstance(ApiLimits::class);

                $worldA = orgHttpWorld('a');
                $worldB = orgHttpWorld('b');
                $tokenA = orgHttpToken($worldA, $worldA['owner']);
                $tokenB = orgHttpToken($worldB, $worldB['owner']);

                orgHttpCall('GET', '/api/v1/organization/branches', $tokenA);
                $exhausted = orgHttpCall('GET', '/api/v1/organization/branches', $tokenA);
                $other = orgHttpCall('GET', '/api/v1/organization/branches', $tokenB);

                $findings = [];

                if ($exhausted->getStatusCode() !== 429) {
                    $findings[] = 'user A was not throttled after exhausting its own bucket';
                }

                if ($other->getStatusCode() === 429) {
                    $findings[] = 'user B was throttled by user A`s bucket';
                }

                return $findings;
            } finally {
                AccessTenantFixture::forget();
            }
        },
        'terminalBucket' => function (): array {
            orgHttpBoot();

            try {
                config()->set('api.limits', ['tenant' => 100000, 'user' => 100000, 'terminal' => 1, 'unauthenticated' => 100000]);
                app()->forgetInstance(ApiLimits::class);

                $worldA = orgHttpWorld('a');
                $tokenA = orgHttpToken($worldA, $worldA['owner']);
                $branch = orgHttpBranch($tokenA, 'tbr1');
                $terminalA = orgHttpActivated($tokenA, $branch, 'tt1');
                $terminalB = orgHttpActivated($tokenA, $branch, 'tt2');

                orgHttpCall('GET', '/api/v1/terminal/settings', $terminalA['access']);
                $exhausted = orgHttpCall('GET', '/api/v1/terminal/settings', $terminalA['access']);
                $other = orgHttpCall('GET', '/api/v1/terminal/settings', $terminalB['access']);

                $findings = [];

                if ($exhausted->getStatusCode() !== 429) {
                    $findings[] = 'terminal A was not throttled after exhausting its own bucket';
                }

                if ($other->getStatusCode() === 429) {
                    $findings[] = 'terminal B was throttled by terminal A`s bucket';
                }

                return $findings;
            } finally {
                AccessTenantFixture::forget();
            }
        },
        'ipBucket' => function (): array {
            orgHttpBoot();

            try {
                config()->set('api.limits', ['tenant' => 100000, 'user' => 100000, 'terminal' => 100000, 'unauthenticated' => 1]);
                app()->forgetInstance(ApiLimits::class);

                \Pest\Laravel\withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
                \Pest\Laravel\json('GET', '/api/v1/demo/ping');

                \Pest\Laravel\withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
                $exhausted = \Pest\Laravel\json('GET', '/api/v1/demo/ping');

                \Pest\Laravel\withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);
                $other = \Pest\Laravel\json('GET', '/api/v1/demo/ping');

                $findings = [];

                if ($exhausted->getStatusCode() !== 429) {
                    $findings[] = 'IP A was not throttled after exhausting its own bucket';
                }

                if ($other->getStatusCode() === 429) {
                    $findings[] = 'IP B was throttled by IP A`s bucket';
                }

                return $findings;
            } finally {
                AccessTenantFixture::forget();
            }
        },
    ];
}

it('AC-16: every *Bucket property on ApiActor has a driver, fail-closed', function () {
    $drivers = qaCacheSweepBucketDrivers();

    foreach (qaCacheSweepBucketProperties() as $name) {
        expect(isset($drivers[$name]))->toBeTrue("No AC-16 driver is registered for ApiActor's [{$name}] property.");
    }
});

it('AC-16: exhausting tenant A`s bucket never throttles tenant B', function () {
    expect((qaCacheSweepBucketDrivers()['tenantBucket'])())->toBe([]);
});

it('AC-16: exhausting user A`s bucket never throttles user B', function () {
    expect((qaCacheSweepBucketDrivers()['userBucket'])())->toBe([]);
});

it('AC-16: exhausting terminal A`s bucket never throttles terminal B', function () {
    expect((qaCacheSweepBucketDrivers()['terminalBucket'])())->toBe([]);
});

it('AC-16: exhausting IP A`s bucket never throttles IP B', function () {
    expect((qaCacheSweepBucketDrivers()['ipBucket'])())->toBe([]);
});

it('negative control: a collapsing limiter double is reported throttling tenant B from tenant A`s bucket', function () {
    orgHttpBoot();

    try {
        config()->set('api.limits.tenant', 1);
        app()->forgetInstance(ApiLimits::class);
        app()->bind(RateLimiter::class, fn () => new QaCacheSweepCollapsingLimiter(app('cache')->driver('array')));

        $worldA = orgHttpWorld('a');
        $worldB = orgHttpWorld('b');
        $tokenA = orgHttpToken($worldA, $worldA['owner']);
        $tokenB = orgHttpToken($worldB, $worldB['owner']);

        orgHttpCall('GET', '/api/v1/organization/branches', $tokenA);
        orgHttpCall('GET', '/api/v1/organization/branches', $tokenA);
        $other = orgHttpCall('GET', '/api/v1/organization/branches', $tokenB);

        expect($other->getStatusCode())->toBe(429, 'tenant B must be throttled by tenant A`s bucket under the collapsing double');
    } finally {
        AccessTenantFixture::forget();
        app()->forgetInstance(RateLimiter::class);
    }
});

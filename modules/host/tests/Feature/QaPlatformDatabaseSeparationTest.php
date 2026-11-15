<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Features\CreateBranch\CreateBranch;
use Flexipos\Organization\Features\CreateBranch\CreateBranchHandler;
use Flexipos\Organization\Tests\Support\FixedTerminalSessions;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Flexipos\Platform\Support\CachingEntitlementSource;
use Flexipos\Platform\Support\MasterEntitlementSource;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-016 step 12 - the topology rule, AC-32 (qa-accountable)
|--------------------------------------------------------------------------
|
| A two-connection query-log scan over a plan-capped `CreateBranch` on the
| master source with a cold cache: every master statement names only
| master tables, every tenant statement names only tenant tables, and no
| statement names a table from the other side (D-3, AC-32). The liveness
| half (AC-5) is proved on a `CachingEntitlementSource` directly: the master
| log is non-empty on a cold cache and empty on a warm one. A source scan
| closes the type-level half: `packages/platform/src` never names the
| tenant connection key, and organization/access never name
| `tenancy.master_connection`.
*/

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
    app()->instance(TerminalSessions::class, new FixedTerminalSessions);
});

afterEach(function () {
    AccessTenantFixture::forget();
    config()->set('platform.stand_in.entitlements', []);
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return list<string>
 */
function qaPlatformMasterTableNames(): array
{
    return masterApplicationTables();
}

/**
 * @return list<string>
 */
function qaPlatformTenantTableNames(): array
{
    return tenantTreeTables();
}

/**
 * @param  list<array{query: string}>  $log
 * @return list<string>
 */
function qaPlatformQueryStrings(array $log): array
{
    return array_map(static fn (array $entry): string => (string) $entry['query'], $log);
}

it('every master statement names only master tables, and every tenant statement names only tenant tables', function () {
    PlatformSeed::migrate();
    PlatformSeed::bindMasterSource();

    $tenantId = 'tenant-a';
    $planId = PlatformSeed::plan();
    PlatformSeed::planLimit($planId, 'branches', 1);
    PlatformSeed::planLimit($planId, 'locations', 10);
    PlatformSeed::subscription($tenantId, $planId, 'active');

    $master = PlatformSeed::connection();
    $tenant = OrganizationSeed::connection();

    $master->flushQueryLog();
    $master->enableQueryLog();
    $tenant->flushQueryLog();
    $tenant->enableQueryLog();

    app(CreateBranchHandler::class)->handle(new CreateBranch([
        'code' => 'ONE', 'name' => 'One', 'timezone' => 'Asia/Colombo', 'currency' => 'LKR',
    ]));

    $masterQueries = qaPlatformQueryStrings($master->getQueryLog());
    $tenantQueries = qaPlatformQueryStrings($tenant->getQueryLog());

    $master->disableQueryLog();
    $tenant->disableQueryLog();

    // Liveness: the plan-limit check really reached the master on this cold
    // cache (bindMasterSource() installs the uncached source), and the
    // tenant count really reached the tenant connection.
    expect($masterQueries)->not->toBe([])
        ->and($tenantQueries)->not->toBe([]);

    $tenantTables = qaPlatformTenantTableNames();
    $masterTables = qaPlatformMasterTableNames();

    foreach ($masterQueries as $query) {
        foreach ($tenantTables as $tenantTable) {
            expect($query)->not->toContain("\"{$tenantTable}\"")
                ->and($query)->not->toContain("`{$tenantTable}`");
        }
    }

    foreach ($tenantQueries as $query) {
        foreach ($masterTables as $masterTable) {
            expect($query)->not->toContain("\"{$masterTable}\"")
                ->and($query)->not->toContain("`{$masterTable}`");
        }
    }
});

it('AC-5 liveness: the master log is non-empty on a cold cache, and empty on a warm one', function () {
    PlatformSeed::migrate();

    $tenantId = PlatformSeed::tenant('tenant-b', 'active');
    $planId = PlatformSeed::plan();
    PlatformSeed::planLimit($planId, 'branches', 5);
    PlatformSeed::subscription($tenantId, $planId, 'active');

    $caching = new CachingEntitlementSource(app(MasterEntitlementSource::class), app('cache.store'), 300);

    $master = PlatformSeed::connection();
    $master->flushQueryLog();
    $master->enableQueryLog();

    $firstLimit = $caching->for($tenantId)->limit(LimitKey::Branches);

    $cold = qaPlatformQueryStrings($master->getQueryLog());
    expect($cold)->not->toBe([])
        ->and($firstLimit->value)->toBe(5);

    $master->flushQueryLog();

    $secondLimit = $caching->for($tenantId)->limit(LimitKey::Branches);

    $warm = qaPlatformQueryStrings($master->getQueryLog());
    $master->disableQueryLog();

    expect($warm)->toBe([])
        ->and($secondLimit->value)->toBe(5);
});

/**
 * Files under the given directory whose code matches a connection-literal
 * pattern - the engine behind both halves of the AC-32 source scan.
 *
 * @return list<string>
 */
function qaPlatformConnectionLiteralOffenders(string $directory, string $pattern): array
{
    $offenders = [];

    if (! is_dir($directory)) {
        return $offenders;
    }

    foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        if (preg_match($pattern, $contents) === 1) {
            $offenders[] = $file->getRealPath();
        }
    }

    return $offenders;
}

it('AC-32 source half: platform never names the tenant connection key, and organization/access never name tenancy.master_connection', function () {
    $tenantConnectionPattern = '/connection\s*\(\s*[\'"]tenant[\'"]\s*\)|[\'"]tenancy\.tenant_connection[\'"]/i';
    $masterConnectionPattern = '/tenancy\.master_connection/';

    expect(qaPlatformConnectionLiteralOffenders(dirname(__DIR__, 4).'/packages/platform/src', $tenantConnectionPattern))->toBe([]);

    foreach (['organization', 'access'] as $package) {
        expect(qaPlatformConnectionLiteralOffenders(dirname(__DIR__, 4)."/packages/{$package}/src", $masterConnectionPattern))->toBe([]);
    }

    // Positive controls: the scan, run over a fresh directory holding each
    // planted reference, finds it - so each empty result above is "nothing
    // found", not "nothing scanned" (T-016 reviewer round 1, W-1: a control
    // that only asserts str_contains() against the planted file's own
    // contents never calls the scanner, and so cannot catch the scanner
    // reading the wrong directory).
    $tenantDirectory = qaPlatformFreshTempDirectory();
    File::put($tenantDirectory.'/Planted.php', "<?php\nconfig('tenancy.tenant_connection');\n");

    $masterDirectory = qaPlatformFreshTempDirectory();
    File::put($masterDirectory.'/Planted.php', "<?php\nconfig('tenancy.master_connection');\n");

    try {
        expect(qaPlatformConnectionLiteralOffenders($tenantDirectory, $tenantConnectionPattern))->toBe([qaPlatformPlantedPath($tenantDirectory)]);
        expect(qaPlatformConnectionLiteralOffenders($masterDirectory, $masterConnectionPattern))->toBe([qaPlatformPlantedPath($masterDirectory)]);
    } finally {
        File::deleteDirectory($tenantDirectory);
        File::deleteDirectory($masterDirectory);
    }
});

<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\MigrateTenant\ArtisanTenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\MigrateTenant\TenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-011: the one named case that still measures the shipped tenant tree
|--------------------------------------------------------------------------
|
| FP-304 moved the planner corpus onto a fixture baseline, so nothing else in
| the kernel corpus reads the product's tenant tree as a planner input. This
| file is where that coverage went, named rather than lost (D-3). Every case is
| in the `shipped-tree` group and reads the tree through runFixtureShippedSet()
| only; QaFixtureBaselineSweepsTest fails on any other reader.
|
| AC-9: V is the highest contract-phase version of the shipped catalogue, and
| the file FAILS - never skips - when there is none. AC-10: that step refuses
| a fleet that is behind, with zero rows and zero tenant connections, and
| plans once the fleet is at V - 1. AC-11: the remedy the refusal prints is
| taken from the message and run for real, with the shipped applier on two
| stand-in tenants, and then V applies. AC-4 and AC-5 (a) also live here,
| because their controls have to read the shipped tree.
|
| V, V - 1 and B are derived; none is written as a number.
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class)->group('shipped-tree');

beforeEach(function () {
    migrateFixtureBoot();

    config()->set('schema.backups.available', false);
});

afterEach(function () {
    migrateFixtureTearDown();

    File::deleteDirectory(shippedTreeScratchRoot());
});

/**
 * Where the scratch trees of AC-4 and AC-9's control live.
 */
function shippedTreeScratchRoot(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'shipped-tree-scratch');
}

/**
 * The failure branch of AC-9: a derivation that found no contract step is a
 * failure, never a skip.
 */
function shippedTreeContractVersionOrFail(?int $version): int
{
    if ($version === null) {
        throw new AssertionFailedError(
            'the shipped-tree gate coverage has nothing to measure: the shipped tenant tree holds no contract-phase migration'
        );
    }

    return $version;
}

/**
 * V, derived from the shipped catalogue.
 */
function shippedTreeV(): int
{
    return shippedTreeContractVersionOrFail(runFixtureContractVersion(runFixtureShippedSet()));
}

/**
 * Every contract-phase version of the shipped catalogue, ascending.
 *
 * @return list<int>
 */
function shippedTreeContractVersions(): array
{
    $versions = [];

    foreach (runFixtureShippedSet()->catalogue() as $entry) {
        if ($entry->phase->value === 'contract') {
            $versions[] = $entry->version;
        }
    }

    return $versions;
}

/**
 * The LOWEST contract step of the shipped catalogue. The guard walks the set
 * ascending, so a fleet at 0 is refused on this one, and the remedy it prints
 * stops just below it. It is V only while V is the only contract step.
 */
function shippedTreeFirstContract(): int
{
    return shippedTreeContractVersionOrFail(shippedTreeContractVersions()[0] ?? null);
}

/**
 * Build a scratch tree from the given files and point the configuration at
 * it. The value stays relative, which is the form the key takes.
 *
 * @param  array<string, string>  $files  file name => source
 */
function shippedTreeUseScratch(string $name, array $files): void
{
    $directory = shippedTreeScratchRoot().DIRECTORY_SEPARATOR.$name;

    File::ensureDirectoryExists($directory);

    foreach ($files as $file => $source) {
        File::put($directory.DIRECTORY_SEPARATOR.$file, $source);
    }

    $relative = ltrim(substr(str_replace('\\', '/', $directory), strlen(str_replace('\\', '/', base_path()))), '/');

    config()->set('schema.tenant_migration_path', $relative);
}

/**
 * The shipped tree's files, as file name => source.
 *
 * @return array<string, string>
 */
function shippedTreeFiles(bool $expandOnly = false): array
{
    $set = runFixtureShippedSet();
    $files = [];

    foreach ($set->catalogue() as $entry) {
        if ($expandOnly && $entry->phase->value !== 'expand') {
            continue;
        }

        $path = (string) $set->pathFor($entry->version);
        $files[basename($path)] = (string) file_get_contents($path);
    }

    return $files;
}

/**
 * One FX file's source.
 */
function shippedTreeFxSource(int $number): string
{
    $paths = MigrationSet::named('fx'.$number, base_path('tests/Fixtures/TenantMigrations/FX'.$number))->paths();

    return (string) file_get_contents($paths[0]);
}

/**
 * @return list<array<string, mixed>>
 */
function shippedTreeRows(MigrationSet $set): array
{
    return array_map(static fn (MigrationCatalogue $entry): array => $entry->toRow(), $set->catalogue());
}

/**
 * Plan through the handler, with the connection instrument around it.
 *
 * @return array{result: mixed, thrown: Throwable|null, queried: list<string>, resolved: list<string>, sql: list<string>}
 */
function shippedTreePlan(MigrationSet $set, ?int $target = null): array
{
    return runFixtureConnectionsUsedBy(static fn () => app(PlanMigrationRunHandler::class)->handle(
        new PlanMigrationRun(set: $set, targetVersion: $target)
    ));
}

/**
 * The first `tenant:migrate ...` invocation in a message, as artisan
 * parameters. Local to this file on purpose: AC-11 takes the remedy from the
 * refusal it is about, with its own parser.
 *
 * @return array<string, string|true>
 */
function shippedTreeInvocationIn(string $message): array
{
    expect(preg_match('/tenant:migrate((?: --[a-z-]+(?:=[^\s`.]+)?)+)/', $message, $found))->toBe(1);

    $parameters = [];

    foreach ((array) preg_split('/ (?=--)/', trim($found[1])) as $option) {
        [$name, $value] = array_pad(explode('=', (string) $option, 2), 2, true);
        $parameters[$name] = $value;
    }

    return $parameters;
}

/**
 * The tables a stand-in tenant holds, read with a bare PDO.
 *
 * @return list<string>
 */
function shippedTreeTenantTables(string $stem): array
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));
    $rows = $pdo->query("select name from sqlite_master where type = 'table'");
    $names = [];

    foreach ($rows === false ? [] : $rows->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (! str_starts_with((string) $name, 'sqlite_')) {
            $names[] = (string) $name;
        }
    }

    sort($names);

    return $names;
}

/**
 * The foreign keys of `settings` in one stand-in tenant, as `column ->
 * table.column`, sorted.
 *
 * @return list<string>
 */
function shippedTreeSettingsKeys(string $stem): array
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));
    $rows = $pdo->query('pragma foreign_key_list("settings")');
    $keys = [];

    foreach ($rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $keys[] = $row['from'].' -> '.$row['table'].'.'.$row['to'];
    }

    sort($keys);

    return $keys;
}

/*
|--------------------------------------------------------------------------
| AC-9 - V exists, or the file fails
|--------------------------------------------------------------------------
*/

it('derives V from the shipped catalogue, and fails rather than skips when there is none (AC-9)', function () {
    $v = shippedTreeV();
    $shipped = runFixtureShippedSet();

    // V is the highest CONTRACT step, not the highest version: a later expand
    // migration must not move it or redden this case (D-16).
    $laterContract = array_filter(
        $shipped->catalogue(),
        static fn ($entry): bool => $entry->version > $v && $entry->phase->value === 'contract'
    );

    expect($shipped->entryFor($v)?->phase->value)->toBe('contract')
        ->and($laterContract)->toBe([]);

    // Control: a tree of the shipped expand files only has nothing to measure,
    // and the failure branch fires on that answer.
    $expandOnly = shippedTreeFiles(expandOnly: true);
    shippedTreeUseScratch('expand-only', $expandOnly);

    expect(runFixtureShippedSet()->highestVersion())->toBe(count($expandOnly))
        ->and(runFixtureContractVersion(runFixtureShippedSet()))->toBeNull()
        ->and(fn () => shippedTreeContractVersionOrFail(runFixtureContractVersion(runFixtureShippedSet())))
        ->toThrow(AssertionFailedError::class, 'the shipped-tree gate coverage has nothing to measure');
});

/*
|--------------------------------------------------------------------------
| AC-4 - the corpus does not move when the shipped tree does
|--------------------------------------------------------------------------
*/

it('builds every runFixtureSet catalogue identically whatever the shipped tree holds (AC-4)', function () {
    $compositions = [[], [1], [4], [1, 5]];
    $real = array_map(static fn (array $fx): array => shippedTreeRows(runFixtureSet(...$fx)), $compositions);
    $shippedReal = shippedTreeRows(runFixtureShippedSet());

    $scratchTrees = [
        'extra-contract' => shippedTreeFiles() + ['9999_12_31_000001_scratch_contract_step.php' => shippedTreeFxSource(4)],
        'extra-expand' => shippedTreeFiles() + [
            '9999_12_31_000001_scratch_expand_one.php' => shippedTreeFxSource(1),
            '9999_12_31_000002_scratch_expand_two.php' => shippedTreeFxSource(2),
        ],
    ];

    foreach ($scratchTrees as $name => $files) {
        shippedTreeUseScratch($name, $files);

        foreach ($compositions as $index => $fx) {
            expect(shippedTreeRows(runFixtureSet(...$fx)))->toBe($real[$index]);
        }

        // Control: the shipped-tree entry point DOES read the scratch tree, so
        // "identical" above is independence and not a reader of nothing.
        expect(shippedTreeRows(runFixtureShippedSet()))->not->toBe($shippedReal)
            ->and(runFixtureShippedSet()->highestVersion())->toBe(count($files));
    }
});

/*
|--------------------------------------------------------------------------
| AC-5 (a) and AC-10 - the shipped contract step gates a fleet that is behind
|--------------------------------------------------------------------------
*/

it('refuses FX-1 composed on the shipped tree for a fleet at 0, naming its first contract step (AC-5 control a)', function () {
    $first = shippedTreeFirstContract();
    $set = runFixtureShippedSet()->with('fx1', base_path('tests/Fixtures/TenantMigrations/FX1'));
    runFixturePublish($set);

    $server = masterMappingServer('shipped-server');
    $tenant = runFixtureTenant('shipped-zero', $server, 'active', 0, 10);

    $touched = shippedTreePlan($set);

    expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
        ->and((string) $touched['thrown']?->getMessage())->toContain('(version '.$first.')')
        ->and((string) $touched['thrown']?->getMessage())->toContain((string) $set->entryFor($first)?->migration)
        ->and((string) $touched['thrown']?->getMessage())->toContain($tenant)
        ->and(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([]);
});

it('refuses the shipped tree for a fleet at 0, naming its first contract step and every blocking tenant, and touches nothing (AC-10)', function () {
    // The lowest contract step: the guard walks the set ascending (D-16).
    $first = shippedTreeFirstContract();
    $set = runFixtureShippedSet();
    runFixturePublish($set);

    $server = masterMappingServer('shipped-server');
    $one = runFixtureTenant('shipped-one', $server, 'active', 0, 10);
    $two = runFixtureTenant('shipped-two', $server, 'read_only', 0, 20);

    $touched = shippedTreePlan($set);
    $message = (string) $touched['thrown']?->getMessage();

    expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
        ->and($message)->toContain('['.$set->entryFor($first)?->migration.'] (version '.$first.')')
        ->and($message)->toContain($one)
        ->and($message)->toContain($two)
        ->and(runFixtureJobRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([])
        ->and($touched['queried'])->toBe(['master'])
        ->and($touched['resolved'])->not->toContain('tenant');
});

it('plans the shipped tree once the fleet is at V - 1, and a provisioning tenant at 0 does not block (AC-10 controls)', function () {
    $v = shippedTreeV();
    $set = runFixtureShippedSet();
    runFixturePublish($set);

    $server = masterMappingServer('shipped-server');
    runFixtureTenant('shipped-one', $server, 'active', $v - 1, 10);
    runFixtureTenant('shipped-two', $server, 'active', $v - 1, 20);
    runFixtureTenant('shipped-new', $server, 'provisioning', 0, 30);

    $touched = shippedTreePlan($set);

    expect($touched['thrown'])->toBeNull()
        ->and(runFixtureRunRows())->toHaveCount(1)
        ->and(array_values(array_unique(array_map(static fn (array $job): int => (int) $job['to_version'], runFixtureJobRows()))))
        ->toBe([$set->highestVersion()]);
});

it('refuses the second plan when the fleet was left below V - 1, as a lower remedy would leave it (AC-11 control)', function () {
    $v = shippedTreeV();
    $set = runFixtureShippedSet();
    runFixturePublish($set);

    $server = masterMappingServer('shipped-server');
    $low = runFixtureTenant('shipped-low', $server, 'active', $v - 2, 10);
    runFixtureTenant('shipped-ready', $server, 'active', $v - 1, 20);

    $touched = shippedTreePlan($set);

    expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class)
        ->and((string) $touched['thrown']?->getMessage())->toContain($low)
        ->and(runFixtureJobRows())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| AC-11 - the printed remedy is run, not read
|--------------------------------------------------------------------------
*/

it('runs the remedy the refusal prints with the shipped applier, then applies V (AC-11)', function () {
    $v = shippedTreeV();
    $set = runFixtureShippedSet();
    runFixturePublish($set);

    app()->bind(TenantMigrationApplier::class, ArtisanTenantMigrationApplier::class);

    $server = migrateFixtureServer();
    $stems = ['tenant-a', 'tenant-b'];

    foreach ($stems as $index => $stem) {
        migrateFixtureTenant($stem, $server, ['size_bytes' => 1024 * ($index + 1)]);
    }

    // Plan to the top; while it is refused, take the remedy from the refusal
    // (never retyped), run it, and plan again. One remedy per contract step:
    // the first stops below the lowest, the last below V (D-16).
    $contracts = shippedTreeContractVersions();
    $remedies = [];
    $planned = null;

    foreach (range(0, count($contracts)) as $round) {
        $touched = shippedTreePlan($set);

        if ($touched['thrown'] === null) {
            $planned = $touched['result'];

            break;
        }

        expect($touched['thrown'])->toBeInstanceOf(MigrationRunRefused::class);

        $remedy = shippedTreeInvocationIn((string) $touched['thrown']->getMessage());

        expect($remedy)->toHaveKey('--all')
            ->and($remedy)->toHaveKey('--to');

        $remedies[] = (int) $remedy['--to'];

        // Without runFixtureCommandsReadTheFixtureBaseline(): the command reads
        // the shipped tree, which is the subject here.
        expect(Artisan::call('tenant:migrate', $remedy))->toBe(0);

        foreach ($stems as $stem) {
            expect((int) migrateFixtureMappingRow(migrateFixtureId($stem))['schema_version'])->toBe((int) $remedy['--to']);
        }
    }

    expect($planned)->not->toBeNull()
        ->and($remedies)->toBe(array_map(static fn (int $contract): int => $contract - 1, $contracts))
        ->and($remedies[0])->toBe(shippedTreeFirstContract() - 1)
        ->and($remedies[count($remedies) - 1])->toBe($v - 1);

    $tablesUpToV = ['migrations'];

    foreach (range(1, $v - 1) as $version) {
        foreach (SourceRules::tablesCreatedIn((string) file_get_contents((string) $set->pathFor($version))) as $table) {
            $tablesUpToV[] = $table;
        }
    }

    sort($tablesUpToV);

    foreach ($stems as $stem) {
        expect((int) migrateFixtureMappingRow(migrateFixtureId($stem))['schema_version'])->toBe($v - 1)
            ->and(shippedTreeTenantTables($stem))->toBe($tablesUpToV)
            ->and(array_keys(migrateFixtureApplyLogOf($stem)))->toHaveCount($v - 1)
            ->and(array_keys(migrateFixtureApplyLogOf($stem)))->not->toContain((string) $set->entryFor($v)?->migration);
    }

    // The plan to the top is now written: run it. It applies V and whatever
    // the shipped tree holds after it (D-16).
    expect(Artisan::call('tenant:migrate', ['--run' => (string) $planned?->runId]))->toBe(0);

    foreach ($stems as $stem) {
        expect((int) migrateFixtureMappingRow(migrateFixtureId($stem))['schema_version'])->toBe($set->highestVersion())
            ->and(array_keys(migrateFixtureApplyLogOf($stem)))->toContain((string) $set->entryFor($v)?->migration)
            ->and(shippedTreeSettingsKeys($stem))->toContain('updated_by -> employees.id');
    }

    expect(DB::connection('master')->table('tenant_migration_runs')->pluck('target_version')->map(fn ($t): int => (int) $t)->all())
        ->toBe([...$remedies, $set->highestVersion()]);
});

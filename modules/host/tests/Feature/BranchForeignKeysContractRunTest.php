<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\MigrateTenant\ArtisanTenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\MigrateTenant\TenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrations;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsResult;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| T-014 step 7 - the branch foreign keys, through the runner
|--------------------------------------------------------------------------
|
| AC-4 (seeded data, fresh tenant, the planner's refusal and remedy) and AC-5's
| runner half (a dirty tenant fails alone, nothing leaks, the retry works).
| The real applier is bound and the tenants are stand-in SQLite files. On
| T-011's pattern (`SettingsUpdatedByContractRunTest`): templates are built by
| ONE migrate call into memory and written out with VACUUM INTO, each tenant
| file is a copy, and the only step the real applier runs is the one under
| test. V, V - 1 and T-011's own contract step are found by migration name.
|
| **The remedy is two runs and is shown as two runs.** A fleet at T-011's top
| is below the version before V, so a run including V is refused, naming V and
| the `--to=` of the remedy; the tenant is then taken to the version before V
| and then to the top. A fleet at 0 needs three: T-011's own contract step has
| the same rule one step earlier.
|
| **The seeded tenant is a data-carrying one, and the seeding is honest.** At
| T-011's top there is no `branches` table, so an attachment can only name a
| branch that does not exist yet; the operator creates the branch between the
| two runs (that is what "the remedy" means for a tenant with attachments), and
| the final run then sees clean data. Every seeded row is asserted unchanged.
*/

require_once __DIR__.'/../../../../packages/kernel/tests/Support/migrate-tenant-fixture.php';

function bfkRunSet(): MigrationSet
{
    return MigrationSet::baseline(migrationTreeDirectory(MigrationTree::Tenant));
}

function bfkRunMigration(): string
{
    return '2026_10_22_000506_add_branch_foreign_keys';
}

function bfkRunV(): int
{
    $version = bfkRunSet()->versionOf(bfkRunMigration());

    if ($version === null) {
        throw new RuntimeException('The shipped tenant tree does not hold '.bfkRunMigration().'.');
    }

    return $version;
}

/**
 * The shipped tree's actual top version, derived - never assumed to equal
 * {@see bfkRunV()}. T-017 added one expand migration after the branch
 * foreign keys contract step, so "run with no explicit target" now reaches
 * a version past V; this is the number that moves when the next task adds
 * another file, and bfkRunV() must not be made to carry that meaning too.
 */
function bfkRunTop(): int
{
    return bfkRunSet()->highestVersion();
}

/** T-011's top: the version of its settings.updated_by contract step. */
function bfkRunT011Top(): int
{
    $version = bfkRunSet()->versionOf('2026_10_21_000401_add_updated_by_foreign_key_to_settings_table');

    if ($version === null) {
        throw new RuntimeException('The tenant tree does not hold T-011\'s contract step.');
    }

    return $version;
}

/**
 * T-029's contract step: the two product tax group keys, the last file of the
 * shipped tree. It carries the same planner rule as V and as T-011's own
 * contract step, so a run that reaches it from further back than the version
 * before it is refused, and the two tests below that take a tenant "to the top"
 * now need one more stop. Found by name, like the other two.
 */
function bfkRunT029V(): int
{
    $version = bfkRunSet()->versionOf('2026_11_10_002912_add_product_tax_group_foreign_keys');

    if ($version === null) {
        throw new RuntimeException('The tenant tree does not hold T-029\'s contract step.');
    }

    return $version;
}

/**
 * T-030's contract step: the one loyalty_transactions.sale_id key, the last file
 * of the shipped tree. It carries the same planner rule as V, as T-011's own
 * contract step and as T-029's, so a run that reaches it from further back than
 * the version before it is refused, and the two tests below that take a tenant
 * "to the top" need one more stop again. Found by name, like the others.
 */
function bfkRunT030V(): int
{
    $version = bfkRunSet()->versionOf('2026_11_10_003010_add_loyalty_sale_foreign_key');

    if ($version === null) {
        throw new RuntimeException('The tenant tree does not hold T-030\'s contract step.');
    }

    return $version;
}

/**
 * T-032's contract step: the owed account keys on products, categories and
 * customers, the last file of the shipped tree. It carries the same planner
 * rule as the steps before it, so a run that reaches it from further back than
 * the version before it is refused, and the two tests below that take a tenant
 * "to the top" need one more stop again. Found by name, like the others.
 */
function bfkRunT032V(): int
{
    $version = bfkRunSet()->versionOf('2026_11_15_003208_add_owed_account_foreign_keys');

    if ($version === null) {
        throw new RuntimeException('The tenant tree does not hold T-032\'s contract step.');
    }

    return $version;
}

function bfkRunTemplatePath(int $upTo): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'bfk-run-template-'.$upTo.'.sqlite');
}

/**
 * Files 1 to $upTo in one migrate call, into memory, out to a file.
 */
function bfkRunBuildTemplate(int $upTo): void
{
    $connection = 'bfk_run_template_'.$upTo;

    config()->set('database.connections.'.$connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    expect(Artisan::call('migrate', [
        '--database' => $connection,
        '--path' => array_slice(bfkRunSet()->paths(), 0, $upTo),
        '--realpath' => true,
        '--force' => true,
    ]))->toBe(0);

    File::delete(bfkRunTemplatePath($upTo));

    DB::connection($connection)->statement('vacuum into '.DB::connection($connection)->getPdo()->quote(bfkRunTemplatePath($upTo)));

    DB::purge($connection);
}

/**
 * One tenant whose database is a copy of a template, mapped at that version.
 */
function bfkRunTenant(string $stem, string $server, int $upTo): string
{
    $tenant = migrateFixtureTenant($stem, $server, ['schema_version' => $upTo]);

    File::copy(bfkRunTemplatePath($upTo), migrateFixtureDatabasePath($stem));

    return $tenant;
}

/**
 * Rows planted with a bare PDO, the way the fixture seeds anything.
 *
 * @param  list<string>  $statements
 */
function bfkRunExec(string $stem, array $statements): void
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));
    $pdo->beginTransaction();

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $pdo->commit();
}

/**
 * Every row of one tenant table.
 *
 * @return list<array<string, mixed>>
 */
function bfkRunRows(string $stem, string $table): array
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));
    $rows = $pdo->query('select * from "'.$table.'" order by 1');

    return $rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<string>
 */
function bfkRunKeys(string $stem, string $table): array
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));
    $rows = $pdo->query('pragma foreign_key_list("'.$table.'")');
    $keys = [];

    foreach ($rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $keys[] = $row['from'].' -> '.$row['table'].'.'.$row['to'];
    }

    sort($keys);

    return $keys;
}

/**
 * Run through the handler, recording every statement on the tenant connection.
 *
 * @param  list<string>|null  $tenantIds
 * @return array{result: RunTenantMigrationsResult, tenantSql: list<string>}
 */
function bfkRunExecute(?array $tenantIds, ?int $target, ?string $runId = null, bool $retryFailed = false): array
{
    $tenantSql = [];

    DB::listen(static function ($query) use (&$tenantSql): void {
        if ($query->connectionName === 'tenant') {
            $tenantSql[] = (string) $query->sql;
        }
    });

    try {
        $result = app(RunTenantMigrationsHandler::class)->handle(new RunTenantMigrations(
            set: bfkRunSet(),
            tenantIds: $tenantIds,
            targetVersion: $runId === null ? $target : null,
            runId: $runId,
            retryFailed: $retryFailed,
            workerId: 'bfk-run-worker',
        ));
    } finally {
        Event::forget('Illuminate\Database\Events\QueryExecuted');
    }

    return ['result' => $result, 'tenantSql' => $tenantSql];
}

/** An id no table holds, which no sink may print. */
function bfkRunSentinel(): string
{
    return 'ZZLEAKZZ7Q9W3E5R1T8Y6U4I2O';
}

beforeEach(function () {
    migrateFixtureBoot();

    app()->bind(TenantMigrationApplier::class, ArtisanTenantMigrationApplier::class);

    runFixturePublish(bfkRunSet());
});

afterEach(function () {
    migrateFixtureTearDown();

    foreach ([0, bfkRunT011Top(), bfkRunV() - 1] as $upTo) {
        File::delete(bfkRunTemplatePath($upTo));
    }
});

/*
|--------------------------------------------------------------------------
| AC-4 - the planner and the remedy
|--------------------------------------------------------------------------
*/

it('refuses a run including the key step for a fleet at T-011s top, naming it and the remedy (AC-4)', function () {
    bfkRunBuildTemplate(bfkRunT011Top());

    $server = migrateFixtureServer();
    $tenant = bfkRunTenant('tenant-a', $server, bfkRunT011Top());

    try {
        bfkRunExecute([$tenant], null);

        $message = null;
    } catch (MigrationRunRefused $refused) {
        $message = $refused->getMessage();
    }

    expect($message)->not->toBeNull()
        ->and($message)->toContain(bfkRunMigration())
        ->and($message)->toContain('version '.bfkRunV())
        ->and($message)->toContain('--to='.(bfkRunV() - 1))
        ->and($message)->toContain('Nothing was planned');

    // Nothing moved: the tenant is where it was.
    expect((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(bfkRunT011Top())
        ->and(runFixtureRunRows())->toBe([]);
});

it('takes a tenant at T-011s top carrying T-010 data to the top in exactly five runs, every seeded row unchanged (AC-4)', function () {
    bfkRunBuildTemplate(bfkRunT011Top());

    $server = migrateFixtureServer();
    $tenant = bfkRunTenant('tenant-a', $server, bfkRunT011Top());
    $branch = 'BRANCHONE00000000000000000';

    // T-010 data at the T-011 tip: employees, a role, assignments at the null
    // scope, a per-person grant, and an attachment naming a branch that does
    // not exist yet (no table does).
    bfkRunExec('tenant-a', [
        "insert into permissions (key, module, \"group\", description, is_branch_scoped) values ('pos.sell', 'pos', 'pos', 'Sell', 1)",
        "insert into employees (id, code, first_name, display_name, status, created_at, updated_at) values ('EMPONE00000000000000000000', 'E1', 'One', 'One', 'active', '2026-10-21 09:00:00.000', '2026-10-21 09:00:00.000')",
        "insert into employees (id, code, first_name, display_name, status, created_at, updated_at) values ('EMPTWO00000000000000000000', 'E2', 'Two', 'Two', 'active', '2026-10-21 09:00:00.000', '2026-10-21 09:00:00.000')",
        "insert into roles (id, key, name, is_system, created_at, updated_at) values ('ROLEONE0000000000000000000', 'owner', 'Owner', 1, '2026-10-21 09:00:00.000', '2026-10-21 09:00:00.000')",
        "insert into employee_roles (id, employee_id, role_id, branch_id, granted_at) values ('ASSIGN100000000000000000000', 'EMPONE00000000000000000000', 'ROLEONE0000000000000000000', null, '2026-10-21 09:00:00.000')",
        "insert into employee_permissions (id, employee_id, permission_key, branch_id, allowed) values ('GRANT1000000000000000000000', 'EMPTWO00000000000000000000', 'pos.sell', null, 1)",
        "insert into employee_branches (employee_id, branch_id, is_default, created_at, updated_at) values ('EMPONE00000000000000000000', '".$branch."', 1, '2026-10-21 09:00:00.000', '2026-10-21 09:00:00.000')",
        "insert into number_sequences (id, branch_id, document_type, reset_period, created_at, updated_at) values ('SEQ10000000000000000000000', null, 'sale', 'never', '2026-10-21 09:00:00.000', '2026-10-21 09:00:00.000')",
    ]);

    $tables = ['employees', 'roles', 'permissions', 'employee_roles', 'employee_permissions', 'employee_branches', 'number_sequences'];
    $before = [];

    foreach ($tables as $table) {
        $before[$table] = bfkRunRows('tenant-a', $table);
    }

    // Run 1: to the last expand version.
    bfkRunExecute([$tenant], bfkRunV() - 1);

    expect((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(bfkRunV() - 1)
        ->and(bfkRunKeys('tenant-a', 'employee_roles'))->not->toContain('branch_id -> branches.id');

    // The operator step between the runs: the branch the attachment names now
    // exists, so the data is clean for the key.
    bfkRunExec('tenant-a', [
        "insert into branches (id, code, name, timezone, currency, created_at, updated_at) values ('".$branch."', 'B1', 'One', 'Asia/Colombo', 'LKR', '2026-10-21 09:00:00.000', '2026-10-21 09:00:00.000')",
    ]);

    // Run 2: through the key step, to the version before T-029's own contract
    // step (which has the same rule, so it needs a run of its own).
    $second = bfkRunExecute([$tenant], bfkRunT029V() - 1)['result'];

    expect($second->jobs[0]->succeeded)->toBeTrue()
        ->and((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(bfkRunT029V() - 1)
        ->and(bfkRunKeys('tenant-a', 'employee_roles'))->toContain('branch_id -> branches.id')
        ->and(bfkRunKeys('tenant-a', 'employee_branches'))->toContain('branch_id -> branches.id')
        ->and(bfkRunKeys('tenant-a', 'number_sequences'))->toContain('branch_id -> branches.id');

    // Run 3: to the version before T-030's own contract step (which has the
    // same rule, so it needs a run of its own).
    $third = bfkRunExecute([$tenant], bfkRunT030V() - 1)['result'];

    expect($third->jobs[0]->succeeded)->toBeTrue()
        ->and((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(bfkRunT030V() - 1);

    // Run 4: to the version before T-032's own contract step (which has the
    // same rule, so it needs a run of its own).
    $fourth = bfkRunExecute([$tenant], bfkRunT032V() - 1)['result'];

    expect($fourth->jobs[0]->succeeded)->toBeTrue()
        ->and((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(bfkRunT032V() - 1);

    // Run 5: to the top.
    $fifth = bfkRunExecute([$tenant], null)['result'];

    expect($fifth->jobs[0]->succeeded)->toBeTrue()
        ->and((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(bfkRunTop());

    foreach ($tables as $table) {
        expect(bfkRunRows('tenant-a', $table))->toBe($before[$table], "[{$table}] rows changed");
    }
});

it('takes a fresh tenant at version 0 to the top, in six runs because each earlier contract step has the same rule (AC-4)', function () {
    bfkRunBuildTemplate(0);

    $server = migrateFixtureServer();
    $tenant = bfkRunTenant('tenant-a', $server, 0);

    // A fresh tenant has no database content to carry; its file is an empty one.
    File::delete(migrateFixtureDatabasePath('tenant-a'));
    (new PDO('sqlite:'.migrateFixtureDatabasePath('tenant-a')))->exec('create table _empty (x integer)');

    $stops = [bfkRunT011Top() - 1, bfkRunV() - 1, bfkRunT029V() - 1, bfkRunT030V() - 1, bfkRunT032V() - 1, null];

    foreach ($stops as $stop) {
        $result = bfkRunExecute([$tenant], $stop)['result'];

        expect($result->jobs[0]->succeeded)->toBeTrue();
    }

    expect((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(bfkRunTop())
        ->and(bfkRunKeys('tenant-a', 'terminals'))->toContain('current_device_id -> devices.id');

    // And one run straight to the top is refused for the same fleet at 0.
    $other = bfkRunTenant('tenant-b', $server, 0);
    File::delete(migrateFixtureDatabasePath('tenant-b'));
    (new PDO('sqlite:'.migrateFixtureDatabasePath('tenant-b')))->exec('create table _empty (x integer)');

    expect(fn () => bfkRunExecute([$other], null))->toThrow(MigrationRunRefused::class);
});

/*
|--------------------------------------------------------------------------
| AC-5, runner half - one dirty tenant fails alone, and nothing leaks
|--------------------------------------------------------------------------
*/

/**
 * A tenant at V - 1 with clean data, and, for each dirty stem, a branch
 * column naming an id no branch holds.
 */
function bfkRunCleanTenant(string $stem, string $server, bool $dirty = false): string
{
    $tenant = bfkRunTenant($stem, $server, bfkRunV() - 1);

    $statements = [
        "insert into employees (id, code, first_name, display_name, status, created_at, updated_at) values ('EMPONE00000000000000000000', 'E1', 'One', 'One', 'active', '2026-10-22 09:00:00.000', '2026-10-22 09:00:00.000')",
        "insert into branches (id, code, name, timezone, currency, created_at, updated_at) values ('BRANCHONE00000000000000000', 'B1', 'One', 'Asia/Colombo', 'LKR', '2026-10-22 09:00:00.000', '2026-10-22 09:00:00.000')",
        "insert into employee_branches (employee_id, branch_id, is_default, created_at, updated_at) values ('EMPONE00000000000000000000', 'BRANCHONE00000000000000000', 1, '2026-10-22 09:00:00.000', '2026-10-22 09:00:00.000')",
        "insert into number_sequences (id, branch_id, document_type, reset_period, created_at, updated_at) values ('SEQ10000000000000000000000', 'BRANCHONE00000000000000000', 'sale', 'never', '2026-10-22 09:00:00.000', '2026-10-22 09:00:00.000')",
    ];

    if ($dirty) {
        $statements[] = "insert into number_sequences (id, branch_id, document_type, reset_period, created_at, updated_at) values ('SEQDIRTY000000000000000000', '".bfkRunSentinel()."', 'refund', 'never', '2026-10-22 09:00:00.000', '2026-10-22 09:00:00.000')";
    }

    bfkRunExec($stem, $statements);

    return $tenant;
}

it('fails a dirty tenant alone, records the count and no value at every sink, and retries it alone once cleaned (AC-5)', function () {
    bfkRunBuildTemplate(bfkRunV() - 1);

    $server = migrateFixtureServer();
    $a = bfkRunCleanTenant('tenant-a', $server);
    $b = bfkRunCleanTenant('tenant-b', $server, dirty: true);
    $c = bfkRunCleanTenant('tenant-c', $server);

    $bBefore = bfkRunRows('tenant-b', 'number_sequences');
    $result = bfkRunExecute(null, bfkRunV())['result'];
    $run = runFixtureRunRows()[0];

    expect($result->tenantIds())->toEqualCanonicalizing([$a, $b, $c])
        ->and((string) $run['status'])->toBe('completed_with_failures')
        ->and((int) migrateFixtureMappingRow($a)['schema_version'])->toBe(bfkRunV())
        ->and((int) migrateFixtureMappingRow($c)['schema_version'])->toBe(bfkRunV())
        ->and((int) migrateFixtureMappingRow($b)['schema_version'])->toBe(bfkRunV() - 1)
        ->and(bfkRunRows('tenant-b', 'number_sequences'))->toBe($bBefore)
        ->and(bfkRunKeys('tenant-b', 'number_sequences'))->toBe([])
        ->and(bfkRunKeys('tenant-a', 'number_sequences'))->toBe(['branch_id -> branches.id']);

    // D-10, every sink the runner writes: the count and the name, never the value.
    $job = collect(runFixtureJobRows())->first(static fn (array $row): bool => (string) $row['tenant_id'] === $b);
    $mapping = migrateFixtureMappingRow($b);
    $returned = collect($result->jobs)->first(static fn ($j): bool => $j->tenantId === $b);

    $sinks = [
        'error_message' => (string) $job['error_message'],
        'error_class' => (string) $job['error_class'],
        'error_migration' => (string) $job['error_migration'],
        'last_migration_error' => (string) $mapping['last_migration_error'],
        'returned errorMessage' => (string) $returned->errorMessage,
    ];

    foreach ($sinks as $name => $sink) {
        expect(str_contains($sink, bfkRunSentinel()))->toBeFalse("[{$name}] leaked the value")
            ->and($sink)->not->toContain(strrev(bfkRunSentinel()));
    }

    expect((string) $job['status'])->toBe('failed')
        ->and((string) $job['error_migration'])->toBe(bfkRunMigration())
        ->and($sinks['error_message'])->toContain('number_sequences.branch_id: 1 row(s)')
        ->and($sinks['last_migration_error'])->toBe($sinks['error_message']);

    // Clean B, then the existing retry route for this run.
    bfkRunExec('tenant-b', ["delete from number_sequences where id = 'SEQDIRTY000000000000000000'"]);

    $before = [
        'a' => [bfkRunRows('tenant-a', 'migrations'), bfkRunRows('tenant-a', 'number_sequences')],
        'c' => [bfkRunRows('tenant-c', 'migrations'), bfkRunRows('tenant-c', 'number_sequences')],
    ];

    $retried = bfkRunExecute(null, null, (string) $run['id'], true)['result'];

    expect($retried->tenantIds())->toBe([$b])
        ->and((int) migrateFixtureMappingRow($b)['schema_version'])->toBe(bfkRunV())
        ->and(bfkRunKeys('tenant-b', 'number_sequences'))->toBe(['branch_id -> branches.id'])
        ->and([
            'a' => [bfkRunRows('tenant-a', 'migrations'), bfkRunRows('tenant-a', 'number_sequences')],
            'c' => [bfkRunRows('tenant-c', 'migrations'), bfkRunRows('tenant-c', 'number_sequences')],
        ])->toBe($before);
});

it('prints the count and no value in the command output, two dirty rows giving 2 (AC-5, D-10)', function () {
    bfkRunBuildTemplate(bfkRunV() - 1);

    $server = migrateFixtureServer();
    $dirty = bfkRunCleanTenant('tenant-dirty', $server, dirty: true);

    bfkRunExec('tenant-dirty', [
        "insert into number_sequences (id, branch_id, document_type, reset_period, created_at, updated_at) values ('SEQDIRTY200000000000000000', '".strrev(bfkRunSentinel())."', 'return', 'never', '2026-10-22 09:00:00.000', '2026-10-22 09:00:00.000')",
    ]);

    $buffer = new BufferedOutput;

    expect(Artisan::call('tenant:migrate', ['--tenant' => [$dirty], '--to' => (string) bfkRunV()], $buffer))->toBe(1);

    $output = $buffer->fetch();

    expect($output)->toContain('failed: ')
        ->and($output)->toContain('number_sequences.branch_id: 2 row(s)')
        ->and($output)->not->toContain(bfkRunSentinel())
        ->and($output)->not->toContain(strrev(bfkRunSentinel()));
});

it('issues the check and no altering statement on a dirty tenant, and the rebuild on a clean one (AC-5)', function () {
    bfkRunBuildTemplate(bfkRunV() - 1);

    $server = migrateFixtureServer();
    $dirty = bfkRunCleanTenant('tenant-dirty', $server, dirty: true);
    $clean = bfkRunCleanTenant('tenant-clean', $server);

    $dirtySql = bfkRunExecute([$dirty], bfkRunV())['tenantSql'];

    $altering = array_values(array_filter($dirtySql, static fn (string $sql): bool => str_contains($sql, '__temp__')
        || str_starts_with(strtolower(ltrim($sql)), 'alter table')
        || str_starts_with(strtolower(ltrim($sql)), 'drop table')));

    $checks = array_values(array_filter($dirtySql, static fn (string $sql): bool => str_contains(strtolower($sql), 'count(*)')
        && str_contains(strtolower($sql), 'not exists')));

    fwrite(STDOUT, PHP_EOL.'AC-5 statements on the dirty tenant ('.count($dirtySql).'), pre-check selects: '.count($checks).PHP_EOL);

    // Every key's pre-check runs first (nine), and nothing alters.
    expect($dirtySql)->not->toBe([])
        ->and(count($checks))->toBe(9)
        ->and($altering)->toBe([]);

    // Liveness (shape 5): the same probe on a clean tenant DOES see the rebuild.
    $cleanSql = bfkRunExecute([$clean], bfkRunV())['tenantSql'];

    expect(array_values(array_filter($cleanSql, static fn (string $sql): bool => str_contains($sql, '__temp__'))))->not->toBe([])
        ->and(bfkRunKeys('tenant-clean', 'number_sequences'))->toBe(['branch_id -> branches.id']);
});

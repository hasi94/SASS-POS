<?php

use Flexipos\Kernel\Schema\Features\MigrateTenant\ArtisanTenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\MigrateTenant\TenantMigrationApplier;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrations;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsResult;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| T-011: the settings.updated_by key, through the runner
|--------------------------------------------------------------------------
|
| AC-17 (the runner half, and D-10 at every place the runner records a
| failure), AC-18 and AC-19. The real applier is bound and the tenants are
| stand-in SQLite files at V - 1, mapped at V - 1. The only step the real
| applier runs is V, which is the subject.
|
| The tenants reach V - 1 by template, not by the applier: files 1 to V - 1
| are applied ONCE, in one migrate call, to an in-memory database written out
| with VACUUM INTO, and each tenant file is a copy of it. Enforcement is left
| at the tenant stanza's default (off), the way the application builds it;
| the "on" half of AC-17 is SettingsUpdatedByKeyTest's.
|
| V, V - 1 and the migration's position are derived from the shipped
| catalogue by the migration's name.
*/

require_once __DIR__.'/../../../../packages/kernel/tests/Support/migrate-tenant-fixture.php';

/**
 * The migration this file is about.
 */
function contractRunMigration(): string
{
    return '2026_10_21_000401_add_updated_by_foreign_key_to_settings_table';
}

function contractRunSet(): MigrationSet
{
    return MigrationSet::baseline(migrationTreeDirectory(MigrationTree::Tenant));
}

function contractRunV(): int
{
    $version = contractRunSet()->versionOf(contractRunMigration());

    if ($version === null) {
        throw new RuntimeException('The shipped tenant tree does not hold '.contractRunMigration().'.');
    }

    return $version;
}

function contractRunTemplatePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'settings-contract-run-template.sqlite');
}

/**
 * Build the V - 1 template once per case: one migrate call into memory, then
 * VACUUM INTO a file.
 */
function contractRunBuildTemplate(): void
{
    $connection = 'settings_contract_template';

    config()->set('database.connections.'.$connection, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $paths = array_slice(contractRunSet()->paths(), 0, contractRunV() - 1);

    expect(Artisan::call('migrate', [
        '--database' => $connection,
        '--path' => $paths,
        '--realpath' => true,
        '--force' => true,
    ]))->toBe(0);

    File::delete(contractRunTemplatePath());

    DB::connection($connection)->statement('vacuum into '.DB::connection($connection)->getPdo()->quote(contractRunTemplatePath()));

    DB::purge($connection);
}

/**
 * One tenant at V - 1, with its database a copy of the template, seeded with
 * a clean settings row - and a row naming an unknown employee for each
 * dirty id given.
 *
 * @param  list<string>  $dirtyIds
 */
function contractRunTenant(string $stem, string $server, array $dirtyIds = []): string
{
    $tenant = migrateFixtureTenant($stem, $server, ['schema_version' => contractRunV() - 1]);

    File::copy(contractRunTemplatePath(), migrateFixtureDatabasePath($stem));

    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));
    $pdo->beginTransaction();
    $pdo->exec("insert into settings_registry (key, module, type) values ('pos.currency', 'test', 'string')");
    $pdo->exec("insert into employees (id, code, first_name, display_name, status, created_at, updated_at) values ('EMPLIVE0000000000000000000', 'EMPLIVE', 'Test', 'Test', 'active', '2026-10-21 09:00:00.000', '2026-10-21 09:00:00.000')");
    $pdo->exec("insert into settings (id, scope_type, scope_id, key, value, updated_by, created_at, updated_at) values ('SETCLEAN000000000000000000', 'company', null, 'pos.currency', '\"LKR\"', 'EMPLIVE0000000000000000000', '2026-10-21 09:00:00.000', '2026-10-21 09:00:00.000')");

    foreach ($dirtyIds as $index => $dirty) {
        $pdo->exec('insert into settings (id, scope_type, scope_id, key, value, updated_by, created_at, updated_at) values ('
            .$pdo->quote(str_pad('SETDIRTY'.$index, 26, '0')).", 'branch', ".$pdo->quote(str_pad('BRANCH'.$index, 26, '0'))
            .", 'pos.currency', '\"x\"', ".$pdo->quote($dirty).", '2026-10-21 09:00:00.000', '2026-10-21 09:00:00.000')");
    }

    $pdo->commit();

    return $tenant;
}

/**
 * The distinctive unknown id D-10's assertions look for.
 */
function contractRunSentinel(): string
{
    return 'ZZLEAKZZ7Q9W3E5R1T8Y6U4I2O';
}

/**
 * Every row of one tenant table, read with a bare PDO.
 *
 * @return list<array<string, mixed>>
 */
function contractRunRows(string $stem, string $table): array
{
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath($stem));
    $rows = $pdo->query('select * from "'.$table.'" order by 1');

    return $rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The foreign keys of `settings` in one tenant.
 *
 * @return list<string>
 */
function contractRunSettingsKeys(string $stem): array
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

/**
 * Run through the handler, recording every statement issued on the tenant
 * connection.
 *
 * A new run targets V explicitly. The subject is this migration's failure
 * isolation, and a later contract step in the shipped tree would otherwise
 * refuse a run to the top from a fleet at V - 1 before this migration ran at
 * all (D-16). A resumed run keeps the target it was planned with.
 *
 * @param  list<string>|null  $tenantIds
 * @return array{result: RunTenantMigrationsResult, tenantSql: list<string>}
 */
function contractRunExecute(?array $tenantIds = null, ?string $runId = null, bool $retryFailed = false): array
{
    $tenantSql = [];

    DB::listen(static function ($query) use (&$tenantSql): void {
        if ($query->connectionName === 'tenant') {
            $tenantSql[] = (string) $query->sql;
        }
    });

    try {
        $result = app(RunTenantMigrationsHandler::class)->handle(new RunTenantMigrations(
            set: contractRunSet(),
            tenantIds: $tenantIds,
            targetVersion: $runId === null ? contractRunV() : null,
            runId: $runId,
            retryFailed: $retryFailed,
            workerId: 'contract-run-worker',
        ));
    } finally {
        Event::forget('Illuminate\Database\Events\QueryExecuted');
    }

    return ['result' => $result, 'tenantSql' => $tenantSql];
}

beforeEach(function () {
    migrateFixtureBoot();

    app()->bind(TenantMigrationApplier::class, ArtisanTenantMigrationApplier::class);

    runFixturePublish(contractRunSet());

    contractRunBuildTemplate();
});

afterEach(function () {
    migrateFixtureTearDown();

    File::delete(contractRunTemplatePath());
});

it('builds the V - 1 template in one migrate call, quickly enough to build per case', function () {
    // The template is the cost every case pays; the plan bounds it at 2 s.
    $started = microtime(true);
    contractRunBuildTemplate();
    $elapsed = microtime(true) - $started;

    $pdo = new PDO('sqlite:'.contractRunTemplatePath());
    $applied = $pdo->query('select migration from migrations order by id');

    expect($applied === false ? [] : $applied->fetchAll(PDO::FETCH_COLUMN))
        ->toBe(array_map(static fn (MigrationCatalogue $entry): string => $entry->migration, array_slice(contractRunSet()->catalogue(), 0, contractRunV() - 1)))
        ->and($elapsed)->toBeLessThan(2.0);
});

/*
|--------------------------------------------------------------------------
| AC-17, runner half, and D-10 at every sink
|--------------------------------------------------------------------------
*/

it('fails a dirty tenant alone, leaves it at V - 1, and records the count without the value (AC-17, D-10)', function () {
    $server = migrateFixtureServer();
    $dirty = contractRunTenant('tenant-dirty', $server, [contractRunSentinel()]);
    $settingsBefore = contractRunRows('tenant-dirty', 'settings');

    $result = contractRunExecute([$dirty])['result'];

    $job = runFixtureJobRows()[0];
    $mapping = migrateFixtureMappingRow($dirty);
    $returned = $result->jobs[0];

    expect($returned->succeeded)->toBeFalse()
        ->and((string) $job['status'])->toBe('failed')
        ->and((string) $job['error_migration'])->toBe(contractRunMigration())
        ->and((int) $mapping['schema_version'])->toBe(contractRunV() - 1)
        ->and(array_column(contractRunRows('tenant-dirty', 'migrations'), 'migration'))->not->toContain(contractRunMigration())
        ->and(contractRunRows('tenant-dirty', 'settings'))->toBe($settingsBefore)
        ->and(contractRunSettingsKeys('tenant-dirty'))->toBe(['key -> settings_registry.key']);

    // D-10, every sink the runner writes: absent value...
    $sinks = [
        'error_message' => (string) $job['error_message'],
        'error_class' => (string) $job['error_class'],
        'error_migration' => (string) $job['error_migration'],
        'last_migration_error' => (string) $mapping['last_migration_error'],
        'returned errorMessage' => (string) $returned->errorMessage,
    ];

    foreach ($sinks as $sink) {
        expect($sink)->not->toContain(contractRunSentinel());
    }

    // ...and, in the same capture, a live message: the count and the name.
    expect($sinks['error_message'])->toContain('1 row(s)')
        ->and($sinks['error_message'])->toContain(contractRunMigration())
        ->and($sinks['last_migration_error'])->toBe($sinks['error_message'])
        ->and($sinks['returned errorMessage'])->toBe($sinks['error_message']);
});

it('prints the count and no value in the command output, two dirty rows giving 2 (AC-17, D-10)', function () {
    $server = migrateFixtureServer();
    $dirty = contractRunTenant('tenant-dirty', $server, [contractRunSentinel(), strrev(contractRunSentinel())]);

    // The command's own buffer: Artisan::output() would hold the applier's
    // nested `migrate` call instead, which shares the console's last-output
    // slot.
    $buffer = new BufferedOutput;

    expect(Artisan::call('tenant:migrate', ['--tenant' => [$dirty], '--to' => (string) contractRunV()], $buffer))->toBe(1);

    $output = $buffer->fetch();

    fwrite(STDOUT, PHP_EOL.'D-10 command output:'.PHP_EOL.$output.PHP_EOL);
    $job = runFixtureJobRows()[0];

    expect($output)->toContain('failed: ')
        ->and($output)->toContain('2 row(s)')
        ->and($output)->not->toContain(contractRunSentinel())
        ->and($output)->not->toContain(strrev(contractRunSentinel()))
        ->and((string) $job['error_message'])->toContain('2 row(s)')
        ->and((string) $job['error_message'])->not->toContain(contractRunSentinel())
        ->and((string) $job['error_message'])->not->toContain(strrev(contractRunSentinel()));
});

/*
|--------------------------------------------------------------------------
| AC-18 - the check is first, and nothing touches settings on dirty data
|--------------------------------------------------------------------------
*/

it('issues the check and no statement that alters settings on a dirty tenant, and the rebuild on a clean one (AC-18)', function () {
    $server = migrateFixtureServer();
    $dirty = contractRunTenant('tenant-dirty', $server, [contractRunSentinel()]);
    $clean = contractRunTenant('tenant-clean', $server);

    $dirtySql = contractRunExecute([$dirty])['tenantSql'];

    fwrite(STDOUT, PHP_EOL.'AC-18 statements on the dirty tenant ('.count($dirtySql).'):'.PHP_EOL.'  '.implode(PHP_EOL.'  ', $dirtySql).PHP_EOL);

    $altering = array_values(array_filter($dirtySql, static fn (string $sql): bool => str_contains($sql, '__temp__')
        || str_contains($sql, 'drop table "settings"')
        || str_starts_with(strtolower(ltrim($sql)), 'alter table')));

    $check = array_values(array_filter($dirtySql, static fn (string $sql): bool => str_contains(strtolower($sql), 'count(*)')
        && str_contains(strtolower($sql), 'not exists')));

    expect($dirtySql)->not->toBe([])
        ->and($check)->toHaveCount(1)
        ->and($altering)->toBe([]);

    // Liveness (shape 5): the same probe on a clean tenant does see the
    // rebuild, so "no altering statement" above is not an absent observer.
    $cleanSql = contractRunExecute([$clean])['tenantSql'];

    expect(array_values(array_filter($cleanSql, static fn (string $sql): bool => str_contains($sql, '__temp__'))))->not->toBe([])
        ->and(contractRunSettingsKeys('tenant-clean'))->toBe(['key -> settings_registry.key', 'updated_by -> employees.id']);
});

/*
|--------------------------------------------------------------------------
| AC-19 - one dirty tenant does not hold up the others, and a retry runs it
| alone
|--------------------------------------------------------------------------
*/

it('takes the clean tenants to V, leaves the dirty one at V - 1, and retries it alone once cleaned (AC-19)', function () {
    $server = migrateFixtureServer();
    $a = contractRunTenant('tenant-a', $server);
    $b = contractRunTenant('tenant-b', $server, [contractRunSentinel()]);
    $c = contractRunTenant('tenant-c', $server);

    $result = contractRunExecute()['result'];
    $run = runFixtureRunRows()[0];

    expect($result->tenantIds())->toEqualCanonicalizing([$a, $b, $c])
        ->and((string) $run['status'])->toBe('completed_with_failures')
        ->and((int) migrateFixtureMappingRow($a)['schema_version'])->toBe(contractRunV())
        ->and((int) migrateFixtureMappingRow($c)['schema_version'])->toBe(contractRunV())
        ->and((int) migrateFixtureMappingRow($b)['schema_version'])->toBe(contractRunV() - 1);

    foreach (['tenant-a', 'tenant-c'] as $stem) {
        expect(contractRunSettingsKeys($stem))->toBe(['key -> settings_registry.key', 'updated_by -> employees.id'])
            ->and(array_column(contractRunRows($stem, 'migrations'), 'migration'))->toContain(contractRunMigration())
            ->and(contractRunRows($stem, 'settings'))->toHaveCount(1);
    }

    $byTenant = [];

    foreach (runFixtureJobRows() as $job) {
        $byTenant[(string) $job['tenant_id']] = (string) $job['status'];
    }

    ksort($byTenant);
    $expected = [$a => 'done', $b => 'failed', $c => 'done'];
    ksort($expected);

    expect($byTenant)->toBe($expected);

    $before = [
        'a' => [contractRunRows('tenant-a', 'migrations'), contractRunRows('tenant-a', 'settings')],
        'c' => [contractRunRows('tenant-c', 'migrations'), contractRunRows('tenant-c', 'settings')],
    ];

    // Clean B, then the existing retry route for this run.
    $pdo = new PDO('sqlite:'.migrateFixtureDatabasePath('tenant-b'));
    $pdo->exec('update settings set updated_by = null where updated_by = '.$pdo->quote(contractRunSentinel()));

    $retried = contractRunExecute(runId: (string) $run['id'], retryFailed: true)['result'];

    expect($retried->tenantIds())->toBe([$b])
        ->and((int) migrateFixtureMappingRow($b)['schema_version'])->toBe(contractRunV())
        ->and(contractRunSettingsKeys('tenant-b'))->toBe(['key -> settings_registry.key', 'updated_by -> employees.id'])
        ->and([
            'a' => [contractRunRows('tenant-a', 'migrations'), contractRunRows('tenant-a', 'settings')],
            'c' => [contractRunRows('tenant-c', 'migrations'), contractRunRows('tenant-c', 'settings')],
        ])->toBe($before);
});

<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Kernel\Schema\Contracts\RecordsMigrationRuns;
use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrations;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Support\PlatformAuditActions;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 5 - the migration-run audit (AC-14, AC-15 by construction, AC-20)
|--------------------------------------------------------------------------
|
| On the kernel run fixture (require_once, FP-164's reason), whole-row
| comparisons against platform_audit_logs. This is a *platform* test rather
| than a kernel one deliberately (plan section 11.5): PlanMigrationRunHandler
| is reached the ordinary way, through the container, so the only thing
| under test is the row MigrationRunAudit produces through PlatformAudit -
| never a second, parallel path.
*/

require_once __DIR__.'/../../../kernel/tests/Support/migration-run-fixture.php';
require_once __DIR__.'/../../../kernel/tests/Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();

    config()->set('schema.backups.available', false);
});

/**
 * @return list<array<string, mixed>>
 */
function migrationRunAuditRows(): array
{
    return array_map(
        static fn (object $row): array => (array) $row,
        DB::connection('master')->table('platform_audit_logs')->where('action', PlatformAuditActions::MIGRATION_RUN)->get()->all()
    );
}

it('writes exactly one row for a bulk run', function () {
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $server = masterMappingServer('bulk-server');
    runFixtureTenant('bulk-tenant', $server, 'active', 0, 1_000);

    $plan = app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set));

    $rows = migrationRunAuditRows();

    expect($rows)->toHaveCount(1);

    $row = $rows[0];

    expect($row['action'])->toBe('migration.run')
        ->and($row['subject_type'])->toBe('tenant_migration_run')
        ->and($row['subject_id'])->toBe($plan->runId)
        ->and($row['tenant_id'])->toBeNull()
        ->and($row['actor_type'])->toBe('system')
        ->and($row['actor_id'])->toBeNull()
        ->and($row['before'])->toBeNull();

    $after = json_decode((string) $row['after'], true);

    expect($after)->toBe([
        'target_version' => $plan->targetVersion,
        'mode' => 'bulk',
        'selector' => [
            'mode' => 'bulk',
            'tenants' => null,
            'target_version' => $plan->targetVersion,
            'set' => $set->name,
        ],
    ]);
});

it('names the tenant for a one-tenant named run, and names none for a two-tenant one', function () {
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $server = masterMappingServer('named-server');
    $tenantOne = runFixtureTenant('named-one', $server, 'active', 0, 1_000);
    $tenantTwo = runFixtureTenant('named-two', $server, 'active', 0, 1_000);

    app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set, tenantIds: [$tenantOne]));

    $single = migrationRunAuditRows();

    expect($single)->toHaveCount(1)
        ->and($single[0]['tenant_id'])->toBe($tenantOne);

    app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set, tenantIds: [$tenantOne, $tenantTwo]));

    $rows = migrationRunAuditRows();

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['tenant_id'])->toBeNull();
});

it('AC-14: gives actor_type user, the actor id and the email snapshot when initiatedBy names a seeded user', function () {
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $server = masterMappingServer('actor-server');
    runFixtureTenant('actor-tenant', $server, 'active', 0, 1_000);

    $userId = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    DB::connection('master')->table('users')->insert([
        'id' => $userId,
        'name' => 'Operator',
        'email' => 'operator@example.test',
        'password_hash' => str_repeat('a', 60),
        'status' => 'active',
        'is_platform_staff' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set, initiatedBy: $userId));

    $row = migrationRunAuditRows()[0];

    expect($row['actor_type'])->toBe('user')
        ->and($row['actor_id'])->toBe($userId)
        ->and($row['actor_email'])->toBe('operator@example.test');
});

it('is written once only: a retry-failed resumption never reaches the planner again', function () {
    $applier = migrateFixtureBoot();
    runFixtureCommandsReadTheFixtureBaseline();

    $set = runFixtureSet();
    runFixturePublish($set);

    $server = migrateFixtureServer();
    migrateFixtureTenant('once-tenant', $server);

    // The first invocation plans a genuine run, through the real handler:
    // exactly one audit row.
    $first = app(RunTenantMigrationsHandler::class)->handle(new RunTenantMigrations(set: $set));

    expect(migrationRunAuditRows())->toHaveCount(1);

    // A second invocation that fails one tenant and a `--retry-failed`
    // resumption of the same run id must not grow that count:
    // RunTenantMigrationsHandler::runFor() calls the planner only when the
    // command does not resume (resumes() is true the moment a runId is
    // given), so the audit call site is reached once per run, structurally.
    $applier->onMigration = null;

    $resumed = app(RunTenantMigrationsHandler::class)->handle(new RunTenantMigrations(
        set: $set,
        runId: $first->runId,
        retryFailed: true,
    ));

    expect($resumed->runId)->toBe($first->runId)
        ->and(migrationRunAuditRows())->toHaveCount(1);

    migrateFixtureTearDown();
});

it('a planner refusal writes zero audit rows and zero run rows', function () {
    // FX-5 is a destructive contract step composed directly on the fixture
    // baseline (never on fx1, to avoid two sets claiming the same catalogue
    // position). D-10's gate refuses it before any write, which is the
    // branch this case exists to prove reaches no audit row either.
    $destructiveSet = runFixtureSet(5);
    runFixturePublish($destructiveSet);

    $server = masterMappingServer('destructive-server');
    runFixtureTenant('destructive-tenant', $server, 'active', 0, 1_000);

    config()->set('schema.backups.available', false);

    expect(fn () => app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $destructiveSet)))
        ->toThrow(MigrationRunRefused::class);

    expect(migrationRunAuditRows())->toBe([])
        ->and(runFixtureRunRows())->toBe([]);
});

it('atomicity: a throwing recorder leaves no run row and no job row', function () {
    $set = runFixtureSet(1);
    runFixturePublish($set);

    $server = masterMappingServer('atomic-server');
    runFixtureTenant('atomic-tenant', $server, 'active', 0, 1_000);

    app()->instance(RecordsMigrationRuns::class, new class implements RecordsMigrationRuns
    {
        public function recordPlanned(
            Connection $master,
            string $runId,
            int $targetVersion,
            string $mode,
            ?array $tenantIds,
            string $setName,
            ?string $initiatedBy,
        ): void {
            throw new RuntimeException('injected fault');
        }
    });

    expect(fn () => app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set)))
        ->toThrow(RuntimeException::class);

    expect(runFixtureRunRows())->toBe([])
        ->and(runFixtureJobRows())->toBe([]);
});

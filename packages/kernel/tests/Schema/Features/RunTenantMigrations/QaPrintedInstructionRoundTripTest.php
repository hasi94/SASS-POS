<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA, T-008 round 3: a printed instruction is a claim, so call it back
|--------------------------------------------------------------------------
|
| The defect class: a test that asserts a string without establishing that
| the string is true. `tenant:migrate --run=<id>` was printed for a whole step
| and was refused by the command that printed it; the test asserted the text.
|
| Every case here takes the instruction from the output that carried it
| (never retyped), turns it into an Artisan call, and asserts that the
| command's own argument guard does not refuse it. The guard's sentence is the
| instrument: it appears only when the invocation could not run at all.
|
| One case is red by design: the contract-guard refusal's remedy.
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();

    $server = migrateFixtureServer();

    runFixturePublish(runFixtureSet());

    migrateFixtureTenant('tenant-a', $server, ['size_bytes' => 1024]);
    migrateFixtureTenant('tenant-b', $server, ['size_bytes' => 2048]);
});

afterEach(function () {
    migrateFixtureTearDown();
});

/**
 * The first `tenant:migrate ...` invocation in a text, as artisan parameters.
 *
 * @return array<string, string|true>
 */
function qaPrintedInvocation(string $text): array
{
    $text = str_replace('*', '', (string) preg_replace('/\s+/', ' ', $text));

    expect(preg_match('/tenant:migrate((?: --[a-z-]+(?:=[^\s`.]+)?)+)/', $text, $found))->toBe(1);

    $parameters = [];

    foreach ((array) preg_split('/ (?=--)/', trim($found[1])) as $option) {
        [$name, $value] = array_pad(explode('=', (string) $option, 2), 2, true);
        $parameters[$name] = $value;
    }

    expect($parameters)->not->toBe([]);

    return $parameters;
}

/**
 * Call a printed invocation back and say whether the command refused to run it.
 *
 * @param  array<string, string|true>  $parameters
 */
function qaGuardRefuses(array $parameters): bool
{
    Artisan::call('tenant:migrate', $parameters);

    return str_contains((string) preg_replace('/\s+/', ' ', Artisan::output()), 'Name the tenants with --tenant');
}

it('control: the guard sentence is what a bare invocation produces', function () {
    // Without this, "was not refused" is also what a renamed sentence reports.
    expect(qaGuardRefuses(['--to' => '3']))->toBeTrue()
        ->and(qaGuardRefuses(['--all' => true]))->toBeFalse();
});

it('runs the instruction the dispatch path prints', function () {
    Bus::fake();

    Artisan::call('tenant:migrate', ['--all' => true, '--queue' => true]);

    $parameters = qaPrintedInvocation(Artisan::output());

    expect($parameters)->toHaveKey('--run')
        ->and(qaGuardRefuses($parameters))->toBeFalse();
});

it('runs the instruction the outstanding-work warning prints', function () {
    $runId = migrateFixtureRun();
    $server = migrateFixtureId('server-one');

    migrateFixtureClaimedJob($runId, 'JOB0000000000000000000001', migrateFixtureId('tenant-a'), $server, 6, overrides: [
        'status' => 'done', 'finished_at' => '2026-10-15 09:05:00.000',
    ]);
    // Unexpired lease held by another worker: outstanding, and not claimable.
    migrateFixtureClaimedJob($runId, 'JOB0000000000000000000002', migrateFixtureId('tenant-b'), $server, 6, worker: 'worker-two', overrides: [
        'lock_expires_at' => '2999-01-01 00:00:00.000',
    ]);

    Artisan::call('tenant:migrate', ['--run' => $runId]);

    $parameters = qaPrintedInvocation(Artisan::output());

    expect($parameters)->toBe(['--run' => $runId])
        ->and(qaGuardRefuses($parameters))->toBeFalse();
});

it('runs the instruction the status report prints for a drained run', function () {
    $runId = migrateFixtureRun();
    $server = migrateFixtureId('server-one');

    migrateFixtureClaimedJob($runId, 'JOB0000000000000000000001', migrateFixtureId('tenant-a'), $server, 6, overrides: [
        'status' => 'done', 'finished_at' => '2026-10-15 09:05:00.000',
    ]);
    DB::connection('master')->table('tenant_migration_runs')->where('id', $runId)->update(['status' => 'running']);

    config()->set('tenancy.required_schema_version', 6);
    Artisan::call('tenant:schema-status');

    $parameters = qaPrintedInvocation(Artisan::output());

    expect($parameters)->toBe(['--run' => $runId])
        ->and(qaGuardRefuses($parameters))->toBeFalse();
});

it('runs the remedy the contract-guard refusal prints', function () {
    $set = runFixtureSet(4);
    DB::connection('master')->table('schema_versions')->delete();
    runFixturePublish($set);

    DB::connection('master')->table('tenant_databases')->update(['schema_version' => 0]);

    $thrown = null;

    try {
        app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set));
    } catch (MigrationRunRefused $refused) {
        $thrown = $refused;
    }

    expect($thrown)->toBeInstanceOf(MigrationRunRefused::class);

    $parameters = qaPrintedInvocation($thrown->getMessage());

    expect($parameters)->toHaveKey('--to')
        // The remedy is a run that migrates something; an invocation that names
        // neither tenants nor --all is refused before it plans anything.
        ->and(qaGuardRefuses($parameters))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The exemption is as narrow as claimed
|--------------------------------------------------------------------------
*/

it('still refuses every invocation that names neither tenants, --all nor a run', function (array $parameters) {
    expect(qaGuardRefuses($parameters))->toBeTrue()
        ->and(DB::connection('master')->table('tenant_migration_runs')->count())->toBe(0);
})->with([
    'to only' => [['--to' => '3']],
    'retry-failed only' => [['--retry-failed' => true]],
    'queue only' => [['--queue' => true]],
    'concurrency only' => [['--concurrency' => '4']],
    'worker only' => [['--worker' => 'w']],
    'per-server only' => [['--per-server' => '2']],
]);

it('lets a run id resume work and plan nothing, including an unknown or empty one', function (string $runId) {
    expect(Artisan::call('tenant:migrate', ['--run' => $runId]))->toBe(1)
        // Not a bulk run in disguise: nothing was planned, nothing was written.
        ->and(DB::connection('master')->table('tenant_migration_runs')->count())->toBe(0)
        ->and(DB::connection('master')->table('tenant_migration_jobs')->count())->toBe(0);
})->with([
    'unknown' => ['RUNNOSUCH000000000000000000'],
    'empty string' => [''],
]);

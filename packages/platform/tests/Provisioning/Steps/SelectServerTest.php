<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\SelectServer;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 9: select_server
|--------------------------------------------------------------------------
|
| AC-13, AC-14.
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
});

/**
 * @param  array<string, mixed>  $overrides
 */
function selectServerRow(array $overrides = []): string
{
    $id = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    PlatformSeed::connection()->table('database_servers')->insert(array_merge([
        'id' => $id,
        'code' => 'db-'.strtolower(substr($id, -6)),
        'name' => 'Server',
        'host' => 'db.internal',
        'port' => 3306,
        'engine' => 'mysql',
        'tls_mode' => 'preferred',
        'provisioner_credential_ref' => 'file://provisioner/db',
        'tier' => 'shared',
        'max_tenants' => 100,
        'tenant_count' => 0,
        'status' => 'active',
        'accepts_new_tenants' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

/**
 * @param  array<string, mixed>  $input
 */
function selectServerContext(array $input = []): RunContext
{
    $runId = app(ProvisioningRunner::class)
        ->plan('create', $input, null, 'worker-1', [StepName::SelectServer]);

    return new RunContext($runId, $input, null);
}

function selectServerJournal(RunContext $context): StepJournal
{
    return new StepJournal(app(ProvisioningRuns::class), $context->runId, StepName::SelectServer);
}

it('picks the qualifying server with the lowest tenant_count', function () {
    selectServerRow(['code' => 'db-full-ish', 'tenant_count' => 40]);
    $winnerId = selectServerRow(['code' => 'db-light', 'tenant_count' => 5]);
    selectServerRow(['code' => 'db-draining', 'status' => 'draining']);

    $context = selectServerContext();
    $output = app(SelectServer::class)->run($context, selectServerJournal($context));

    expect($output['server_id'])->toBe($winnerId)
        ->and($output['server_code'])->toBe('db-light');

    $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $context->runId)->first();
    expect($run->target_server_id)->toBe($winnerId);

    // AC-14: nothing on database_servers moved.
    $row = PlatformSeed::connection()->table('database_servers')->where('id', $winnerId)->first();
    expect((int) $row->tenant_count)->toBe(5);
});

it('names every excluding condition when nothing qualifies', function () {
    selectServerRow(['code' => 'db-draining', 'status' => 'draining']);
    selectServerRow(['code' => 'db-closed', 'accepts_new_tenants' => false]);
    selectServerRow(['code' => 'db-full', 'tenant_count' => 10, 'max_tenants' => 10]);

    $context = selectServerContext();

    $caught = null;

    try {
        app(SelectServer::class)->run($context, selectServerJournal($context));
    } catch (PlatformRefused $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toContain('db-draining')
        ->and($caught->getMessage())->toContain('db-closed')
        ->and($caught->getMessage())->toContain('db-full');
});

it('refuses a failing --server override with no fallback to automatic selection', function () {
    selectServerRow(['code' => 'db-light', 'tenant_count' => 1]);
    selectServerRow(['code' => 'db-draining', 'status' => 'draining']);

    $context = selectServerContext(['server_code' => 'db-draining']);

    expect(fn () => app(SelectServer::class)->run($context, selectServerJournal($context)))
        ->toThrow(PlatformRefused::class, 'db-draining');

    $run = PlatformSeed::connection()->table('tenant_provisioning_runs')->where('id', $context->runId)->first();
    expect($run->target_server_id)->toBeNull();
});

it('honours a qualifying --server override even when it is not the lowest-utilisation choice', function () {
    selectServerRow(['code' => 'db-light', 'tenant_count' => 1]);
    $overrideId = selectServerRow(['code' => 'db-chosen', 'tenant_count' => 50]);

    $context = selectServerContext(['server_code' => 'db-chosen']);
    $output = app(SelectServer::class)->run($context, selectServerJournal($context));

    expect($output['server_id'])->toBe($overrideId);
});

it('writes nothing to database_servers itself (AC-14)', function () {
    $serverId = selectServerRow(['code' => 'db-light', 'tenant_count' => 3]);
    $before = (array) PlatformSeed::connection()->table('database_servers')->where('id', $serverId)->first();

    $context = selectServerContext();
    app(SelectServer::class)->run($context, selectServerJournal($context));

    $after = (array) PlatformSeed::connection()->table('database_servers')->where('id', $serverId)->first();
    expect($after)->toBe($before);
});

it('is idempotent on resume: the journalled selection is reused, not re-evaluated', function () {
    $winnerId = selectServerRow(['code' => 'db-light', 'tenant_count' => 1]);

    $context = selectServerContext();
    $journal = selectServerJournal($context);
    $first = app(SelectServer::class)->run($context, $journal);

    // Capacity moves after the first selection; a re-evaluation would now
    // pick differently, which is exactly what idempotency must not do.
    selectServerRow(['code' => 'db-lighter', 'tenant_count' => 0]);

    $second = app(SelectServer::class)->run($context, $journal);

    expect($second)->toBe($first)
        ->and($second['server_id'])->toBe($winnerId);
});

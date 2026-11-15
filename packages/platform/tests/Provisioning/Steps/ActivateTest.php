<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\Activate;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Flexipos\Platform\Tests\Support\ProvisioningTenantFixture;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 14: activate
|--------------------------------------------------------------------------
|
| AC-33 to AC-36.
*/

uses(TestCase::class);

beforeEach(function () {
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
    ProvisioningTenantFixture::boot();
});

afterEach(function () {
    ProvisioningTenantFixture::forget();
    app(Tenancy::class)->leave();
});

afterAll(function () {
    ProvisioningTenantFixture::deleteRoot();
});

/**
 * @param  array<string, mixed>  $input
 * @return array{0: RunContext, 1: StepJournal, 2: string, 3: string}
 */
function activateFixture(string $code, array $input = []): array
{
    $planId = PlatformSeed::plan();
    $created = ProvisioningTenantFixture::create($code);
    $tenantId = $created['tenant_id'];
    $serverId = $created['server_id'];

    $input = array_merge([
        'plan_id' => $planId,
        'active' => false,
    ], $input);

    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        $input,
        null,
        'worker-1',
        [StepName::ReserveIdentity, StepName::SelectServer, StepName::Activate],
    );
    $context = new RunContext($runId, $input, null);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity))->write([
        'tenant_id' => $tenantId,
    ]);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::SelectServer))->write([
        'server_id' => $serverId,
    ]);

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::Activate);

    return [$context, $journal, $tenantId, $serverId];
}

it('activates the mapping, increments tenant_count, starts the subscription and moves tenants.status (AC-33, AC-34)', function () {
    [$context, $journal, $tenantId, $serverId] = activateFixture('TEN201');

    app(Activate::class)->run($context, $journal);

    $mapping = DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->first();
    expect($mapping->status)->toBe('active');

    $server = DB::connection('master')->table('database_servers')->where('id', $serverId)->first();
    expect((int) $server->tenant_count)->toBe(1);

    $subscription = DB::connection('master')->table('subscriptions')->where('tenant_id', $tenantId)->first();
    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe('trial');

    $tenant = DB::connection('master')->table('tenants')->where('id', $tenantId)->first();
    expect($tenant->status)->toBe('trial')
        ->and($tenant->activated_at)->not->toBeNull();
});

it('starts the subscription active under --active', function () {
    [$context, $journal, $tenantId] = activateFixture('TEN202', ['active' => true]);

    app(Activate::class)->run($context, $journal);

    $tenant = DB::connection('master')->table('tenants')->where('id', $tenantId)->first();
    $subscription = DB::connection('master')->table('subscriptions')->where('tenant_id', $tenantId)->first();

    expect($tenant->status)->toBe('active')
        ->and($subscription->status)->toBe('active');
});

it('AC-35: re-entry writes nothing - no second subscription and no second tenant_count increment', function () {
    [$context, $journal, $tenantId, $serverId] = activateFixture('TEN203');

    app(Activate::class)->run($context, $journal);
    app(Activate::class)->run($context, $journal);

    $server = DB::connection('master')->table('database_servers')->where('id', $serverId)->first();
    expect((int) $server->tenant_count)->toBe(1);

    $subscriptionCount = DB::connection('master')->table('subscriptions')->where('tenant_id', $tenantId)->count();
    expect($subscriptionCount)->toBe(1);

    $mapping = DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->first();
    expect($mapping->status)->toBe('active');
});

it('AC-36: a serving Tenancy::enter() now succeeds', function () {
    [$context, $journal, $tenantId] = activateFixture('TEN204');

    app(Activate::class)->run($context, $journal);

    app(Tenancy::class)->enter($tenantId, TenantConnectionPurpose::Serving);
    $resolvedTenantId = app(Tenancy::class)->current()->tenantId();
    app(Tenancy::class)->leave();

    expect($resolvedTenantId)->toBe($tenantId);
});

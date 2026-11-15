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
use Flexipos\Platform\Provisioning\Steps\WriteMapping;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 10: write_mapping
|--------------------------------------------------------------------------
|
| AC-19.
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
});

/**
 * @return array{0: RunContext, 1: StepJournal, 2: string, 3: string}
 */
function writeMappingFixture(): array
{
    $tenantId = PlatformSeed::tenant('TEN001', 'provisioning');

    $serverId = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();
    PlatformSeed::connection()->table('database_servers')->insert([
        'id' => $serverId,
        'code' => 'srv1',
        'name' => 'Server 1',
        'host' => '127.0.0.1',
        'port' => 3306,
        'engine' => 'mysql',
        'tls_mode' => 'required',
        'provisioner_credential_ref' => 'env://ADMIN',
        'tier' => 'shared',
        'max_tenants' => 100,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        [],
        null,
        'worker-1',
        [StepName::ReserveIdentity, StepName::SelectServer, StepName::CreateDatabase, StepName::StoreCredentials, StepName::WriteMapping],
    );
    $context = new RunContext($runId, [], null);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity))->write([
        'tenant_id' => $tenantId,
        'sequence' => 1,
        'code' => 'TEN001',
        'database_name' => 'flx_t_000001',
        'app_username' => 'flx_t000001_app',
        'migrate_username' => 'flx_t000001_mig',
    ]);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::SelectServer))->write([
        'server_id' => $serverId,
        'server_code' => 'srv1',
        'host' => '127.0.0.1',
        'port' => 3306,
        'engine' => 'mysql',
        'tls_mode' => 'required',
        'provisioner_credential_ref' => 'env://ADMIN',
    ]);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::CreateDatabase))->write([
        'database_name' => 'flx_t_000001',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::StoreCredentials))->write([
        'app_credential_ref' => 'file:///tmp/tenant_000001_app',
        'migrate_credential_ref' => 'file:///tmp/tenant_000001_mig',
    ]);

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::WriteMapping);

    return [$context, $journal, $tenantId, $serverId];
}

it('writes exactly AC-19\'s row', function () {
    [$context, $journal, $tenantId, $serverId] = writeMappingFixture();

    app(WriteMapping::class)->run($context, $journal);

    $row = (array) PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $tenantId)->first();

    expect($row['server_id'])->toBe($serverId)
        ->and($row['database_identifier'])->toBe('TEN001')
        ->and($row['database_name'])->toBe('flx_t_000001')
        ->and($row['app_username'])->toBe('flx_t000001_app')
        ->and($row['app_credential_ref'])->toBe('file:///tmp/tenant_000001_app')
        ->and($row['migrate_username'])->toBe('flx_t000001_mig')
        ->and($row['migrate_credential_ref'])->toBe('file:///tmp/tenant_000001_mig')
        ->and($row['charset'])->toBe('utf8mb4')
        ->and($row['collation'])->toBe('utf8mb4_unicode_ci')
        ->and($row['status'])->toBe('provisioning')
        ->and((int) $row['schema_version'])->toBe(0);
});

it('is idempotent when the row already exists with equal values', function () {
    [$context, $journal, $tenantId] = writeMappingFixture();

    app(WriteMapping::class)->run($context, $journal);
    app(WriteMapping::class)->run($context, $journal);

    expect(PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $tenantId)->count())->toBe(1);
});

it('refuses when an existing row for this tenant differs from this run\'s own value', function () {
    [$context, $journal, $tenantId] = writeMappingFixture();

    app(WriteMapping::class)->run($context, $journal);

    PlatformSeed::connection()->table('tenant_databases')->where('tenant_id', $tenantId)->update([
        'charset' => 'latin1',
    ]);

    expect(fn () => app(WriteMapping::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'charset');
});

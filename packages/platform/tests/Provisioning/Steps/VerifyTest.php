<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\OwnerCredentialAnnouncement;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\CreateOwner;
use Flexipos\Platform\Provisioning\Steps\SeedCore;
use Flexipos\Platform\Provisioning\Steps\Verify;
use Flexipos\Platform\Tests\Support\ProvisioningTenantFixture;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 14: verify
|--------------------------------------------------------------------------
|
| AC-32.
*/

uses(TestCase::class);

beforeEach(function () {
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
    app()->forgetInstance(OwnerCredentialAnnouncement::class);
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
 * @return array{0: RunContext, 1: StepJournal, 2: string}
 */
function verifyFixture(string $code, array $input = []): array
{
    $created = ProvisioningTenantFixture::create($code);
    $tenantId = $created['tenant_id'];

    $input = array_merge([
        'name' => 'Shop '.$code,
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'business_type' => 'retail',
        'branch_code' => null,
        'branch_name' => null,
        'owner_email' => strtolower($code).'-owner@example.com',
        'owner_name' => null,
    ], $input);

    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        $input,
        null,
        'worker-1',
        [StepName::ReserveIdentity, StepName::SeedCore, StepName::CreateOwner, StepName::Verify],
    );
    $context = new RunContext($runId, $input, null);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity))->write([
        'tenant_id' => $tenantId,
    ]);

    $seedJournal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::SeedCore);
    $seedJournal->write(app(SeedCore::class)->run($context, $seedJournal));

    $ownerJournal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::CreateOwner);
    $ownerJournal->write(app(CreateOwner::class)->run($context, $ownerJournal));

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::Verify);

    return [$context, $journal, $tenantId];
}

it('passes every AC-32 assertion for a correctly seeded and owned tenant', function () {
    [$context, $journal] = verifyFixture('TEN101');

    $output = app(Verify::class)->run($context, $journal);

    expect($output)->toBe([]);
});

it('fails naming the migrations assertion when a migration row is deleted', function () {
    [$context, $journal, $tenantId] = verifyFixture('TEN102');

    app(Tenancy::class)->run($tenantId, function () {
        DB::connection((string) config('tenancy.connection', 'tenant'))->table('migrations')->limit(1)->delete();
    }, TenantConnectionPurpose::Migrating);

    expect(fn () => app(Verify::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'migrations');
});

it('fails naming the owner-keys assertion when a role_permissions row is deleted', function () {
    [$context, $journal, $tenantId] = verifyFixture('TEN103');

    app(Tenancy::class)->run($tenantId, function () {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));
        $role = $connection->table('roles')->where('key', 'owner')->first();
        $connection->table('role_permissions')->where('role_id', $role->id)->limit(1)->delete();
    }, TenantConnectionPurpose::Migrating);

    expect(fn () => app(Verify::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'owner role is missing');
});

it('fails naming the schema_meta assertion when the stamp names another tenant', function () {
    [$context, $journal, $tenantId] = verifyFixture('TEN104');

    app(Tenancy::class)->run($tenantId, function () {
        DB::connection((string) config('tenancy.connection', 'tenant'))
            ->table('schema_meta')
            ->where('key', 'tenant_id')
            ->update(['value' => 'some-other-tenant-id']);
    }, TenantConnectionPurpose::Migrating);

    expect(fn () => app(Verify::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'schema_meta');
});

it('fails naming the branch assertion when the branch is deleted', function () {
    [$context, $journal, $tenantId] = verifyFixture('TEN105');

    $seeded = app(ProvisioningRuns::class)->stepOutput($context->runId, StepName::SeedCore->value);
    $branchId = (string) $seeded['branch_id'];

    app(Tenancy::class)->run($tenantId, function () use ($branchId) {
        DB::connection((string) config('tenancy.connection', 'tenant'))
            ->table('branches')->where('id', $branchId)
            ->update(['deleted_at' => app(RunnerClock::class)->timestamp()]);
    }, TenantConnectionPurpose::Migrating);

    expect(fn () => app(Verify::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'branch');
});

it('fails naming the owner-attachment assertion when OWNER is detached from the branch', function () {
    [$context, $journal, $tenantId] = verifyFixture('TEN106');

    app(Tenancy::class)->run($tenantId, function () {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));
        $employee = $connection->table('employees')->where('code', 'OWNER')->first();
        $connection->table('employee_branches')->where('employee_id', $employee->id)->delete();
    }, TenantConnectionPurpose::Migrating);

    expect(fn () => app(Verify::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'not attached to branch');
});

it('fails naming the tenant_users assertion when the link is unlinked', function () {
    [$context, $journal, $tenantId] = verifyFixture('TEN107');

    $owner = app(ProvisioningRuns::class)->stepOutput($context->runId, StepName::CreateOwner->value);
    $userId = (string) $owner['user_id'];

    DB::connection('master')->table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $userId)->delete();

    expect(fn () => app(Verify::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'tenant_users');
});

it('refuses before entry when the mapping status is not provisioning (Ruling 6)', function () {
    [$context, $journal, $tenantId] = verifyFixture('TEN108');

    DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->update(['status' => 'active']);

    expect(fn () => app(Verify::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'provisioning');
});

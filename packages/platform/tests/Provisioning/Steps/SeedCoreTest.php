<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Provisioning\Steps;

use Flexipos\Contracts\Access\OwnerSeed;
use Flexipos\Contracts\Access\TenantAccessBootstrap;
use Flexipos\Kernel\Schema\Features\MigrateTenant\SchemaMetaIdentity;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningRunner;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Provisioning\Steps\SeedCore;
use Flexipos\Platform\Tests\Support\ProvisioningTenantFixture;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 12: seed_core
|--------------------------------------------------------------------------
|
| AC-25, AC-25a, AC-26, AC-27.
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
 * @return array{0: RunContext, 1: StepJournal, 2: string}
 */
function seedCoreFixture(array $input = []): array
{
    $created = ProvisioningTenantFixture::create('TEN001');
    $tenantId = $created['tenant_id'];

    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        array_merge([
            'name' => 'Shop TEN001',
            'timezone' => 'Asia/Colombo',
            'currency' => 'LKR',
            'business_type' => 'retail',
            'branch_code' => null,
            'branch_name' => null,
        ], $input),
        null,
        'worker-1',
        [StepName::ReserveIdentity, StepName::SeedCore],
    );
    $context = new RunContext($runId, array_merge([
        'name' => 'Shop TEN001',
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'business_type' => 'retail',
        'branch_code' => null,
        'branch_name' => null,
    ], $input), null);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity))->write([
        'tenant_id' => $tenantId,
    ]);

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::SeedCore);

    return [$context, $journal, $tenantId];
}

it('stamps schema_meta, seeds permissions and creates the default branch (AC-25, AC-25a, AC-26)', function () {
    [$context, $journal, $tenantId] = seedCoreFixture();

    $output = app(SeedCore::class)->run($context, $journal);

    expect($output['branch_id'])->toBeString();

    app(Tenancy::class)->run($tenantId, function () use ($tenantId, $output) {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        expect(SchemaMetaIdentity::of($connection))->toBe($tenantId);

        $ownerRole = $connection->table('roles')->where('key', 'owner')->where('is_system', true)->first();
        expect($ownerRole)->not->toBeNull();

        $branch = $connection->table('branches')->where('id', $output['branch_id'])->whereNull('deleted_at')->first();
        expect($branch)->not->toBeNull()
            ->and($branch->code)->toBe('MAIN')
            ->and($branch->name)->toBe('Shop TEN001');
    }, TenantConnectionPurpose::Migrating);
});

it('uses the supplied branch code/name over the defaults (AC-26)', function () {
    [$context, $journal, $tenantId] = seedCoreFixture(['branch_code' => 'HQ', 'branch_name' => 'Head Office']);

    $output = app(SeedCore::class)->run($context, $journal);

    app(Tenancy::class)->run($tenantId, function () use ($output) {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));
        $branch = $connection->table('branches')->where('id', $output['branch_id'])->first();

        expect($branch->code)->toBe('HQ')
            ->and($branch->name)->toBe('Head Office');
    }, TenantConnectionPurpose::Migrating);
});

it('is idempotent on re-entry: one stamp, one branch', function () {
    [$context, $journal] = seedCoreFixture();

    $first = app(SeedCore::class)->run($context, $journal);
    $second = app(SeedCore::class)->run($context, $journal);

    expect($second)->toBe($first);
});

it('refuses when the permission registry refuses a key, and no branch is created (AC-27)', function () {
    [$context, $journal, $tenantId] = seedCoreFixture();

    app()->instance(TenantAccessBootstrap::class, new class implements TenantAccessBootstrap
    {
        public function seedPermissions(): array
        {
            return ['fixture.conflict'];
        }

        public function ensureOwnerEmployee(OwnerSeed $seed): string
        {
            throw new RuntimeException('not reached');
        }

        public function attachOwnerBranch(string $employeeId, string $branchId): void
        {
            throw new RuntimeException('not reached');
        }

        public function grantOwner(string $employeeId): void
        {
            throw new RuntimeException('not reached');
        }

        public function linkUser(string $employeeId, string $userId): void
        {
            throw new RuntimeException('not reached');
        }

        public function ownerMissingKeys(): array
        {
            throw new RuntimeException('not reached');
        }

        public function ownerState(): ?array
        {
            throw new RuntimeException('not reached');
        }
    });

    expect(fn () => app(SeedCore::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'fixture.conflict');

    app(Tenancy::class)->run($tenantId, function () {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        expect($connection->table('branches')->count())->toBe(0);
    }, TenantConnectionPurpose::Migrating);
});

it('refuses before entry when the mapping status is not provisioning (Ruling 6)', function () {
    [$context, $journal, $tenantId] = seedCoreFixture();

    DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->update(['status' => 'active']);

    expect(fn () => app(SeedCore::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'provisioning');
});

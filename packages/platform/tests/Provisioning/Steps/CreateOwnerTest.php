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
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Flexipos\Platform\Tests\Support\ProvisioningTenantFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 13: create_owner
|--------------------------------------------------------------------------
|
| AC-29, AC-30, AC-31, D-26.
*/

uses(TestCase::class);

beforeEach(function () {
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
    app()->forgetInstance(OwnerCredentialAnnouncement::class);
    ProvisioningTenantFixture::boot();
});

afterEach(function () {
    CreateOwner::$masterFailureHook = null;
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
function createOwnerFixture(string $code, array $input = []): array
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
        'owner_email' => 'owner@example.com',
        'owner_name' => null,
    ], $input);

    $runId = app(ProvisioningRunner::class)->plan(
        'create',
        $input,
        null,
        'worker-1',
        [StepName::ReserveIdentity, StepName::SeedCore, StepName::CreateOwner],
    );
    $context = new RunContext($runId, $input, null);

    (new StepJournal(app(ProvisioningRuns::class), $runId, StepName::ReserveIdentity))->write([
        'tenant_id' => $tenantId,
    ]);

    $seedJournal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::SeedCore);
    $seedOutput = app(SeedCore::class)->run($context, $seedJournal);
    $seedJournal->write($seedOutput);

    $journal = new StepJournal(app(ProvisioningRuns::class), $runId, StepName::CreateOwner);

    return [$context, $journal, $tenantId, $runId];
}

it('creates a brand-new owner login, employee, branch link and role grant (AC-29)', function () {
    [$context, $journal, $tenantId] = createOwnerFixture('TEN001', ['owner_email' => 'new.owner@example.com']);

    $output = app(CreateOwner::class)->run($context, $journal);

    expect($output['mode'])->toBe('created')
        ->and($output['employee_id'])->toBeString()
        ->and($output['user_id'])->toBeString();

    $announcement = app(OwnerCredentialAnnouncement::class);
    expect($announcement->mode())->toBe('created')
        ->and($announcement->password())->toBeString()
        ->and($announcement->password())->not->toBe('');

    $user = PlatformSeed::connection()->table('users')->where('id', $output['user_id'])->first();
    expect($user)->not->toBeNull()
        ->and((bool) $user->must_change_password)->toBeTrue()
        ->and($user->status)->toBe('active')
        ->and(Hash::check((string) $announcement->password(), (string) $user->password_hash))->toBeTrue();

    $tenantUser = PlatformSeed::connection()->table('tenant_users')
        ->where('tenant_id', $tenantId)
        ->where('user_id', $output['user_id'])
        ->first();
    expect($tenantUser)->not->toBeNull()
        ->and($tenantUser->access_level)->toBe('owner')
        ->and((bool) $tenantUser->is_default)->toBeTrue();

    app(Tenancy::class)->run($tenantId, function () use ($output) {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        $employee = $connection->table('employees')->where('id', $output['employee_id'])->first();
        expect($employee)->not->toBeNull()
            ->and($employee->code)->toBe('OWNER')
            ->and((string) $employee->user_id)->toBe($output['user_id']);

        $branchLink = $connection->table('employee_branches')->where('employee_id', $output['employee_id'])->first();
        expect($branchLink)->not->toBeNull()
            ->and((bool) $branchLink->is_default)->toBeTrue();

        $role = $connection->table('roles')->where('key', 'owner')->first();
        $grant = $connection->table('employee_roles')
            ->where('employee_id', $output['employee_id'])
            ->where('role_id', $role->id)
            ->first();
        expect($grant)->not->toBeNull();
    }, TenantConnectionPurpose::Migrating);
});

it('attaches an existing login to a second tenant without modifying it (AC-31, D-30)', function () {
    $existingId = (string) Str::ulid();

    PlatformSeed::connection()->table('users')->insert([
        'id' => $existingId,
        'name' => 'Original Name',
        'email' => 'shared.owner@example.com',
        'password_hash' => Hash::make('original-password-0123456789'),
        'status' => 'active',
        'must_change_password' => false,
        'created_at' => app(RunnerClock::class)->timestamp(),
        'updated_at' => app(RunnerClock::class)->timestamp(),
    ]);

    [$context, $journal, $tenantId] = createOwnerFixture('TEN002', [
        'owner_email' => 'shared.owner@example.com',
        'owner_name' => 'Ignored Name',
    ]);

    $output = app(CreateOwner::class)->run($context, $journal);

    expect($output['mode'])->toBe('attached')
        ->and($output['user_id'])->toBe((string) $existingId);

    $announcement = app(OwnerCredentialAnnouncement::class);
    expect($announcement->mode())->toBe('attached')
        ->and($announcement->password())->toBeNull();

    $user = PlatformSeed::connection()->table('users')->where('id', $existingId)->first();
    expect($user->name)->toBe('Original Name')
        ->and((bool) $user->must_change_password)->toBeFalse()
        ->and($user->status)->toBe('active')
        ->and(Hash::check('original-password-0123456789', (string) $user->password_hash))->toBeTrue();

    $tenantUser = PlatformSeed::connection()->table('tenant_users')
        ->where('tenant_id', $tenantId)
        ->where('user_id', $existingId)
        ->first();
    expect($tenantUser)->not->toBeNull()
        ->and((bool) $tenantUser->is_default)->toBeFalse();

    $usersCount = PlatformSeed::connection()->table('users')->where('email_lower', 'shared.owner@example.com')->count();
    expect($usersCount)->toBe(1);

    app(Tenancy::class)->run($tenantId, function () use ($output) {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));
        $employee = $connection->table('employees')->where('id', $output['employee_id'])->first();

        // AC-31: --owner-name is ignored when attaching - the employee's own
        // display name still defaults from the email's local part.
        expect($employee->display_name)->toBe('shared.owner');
    }, TenantConnectionPurpose::Migrating);
});

it('resumes after a master-side failure injected right after the tenant writes commit (AC-30)', function () {
    [$context, $journal, $tenantId] = createOwnerFixture('TEN003');

    CreateOwner::$masterFailureHook = function () {
        throw new RuntimeException('simulated master outage, right after the tenant writes committed');
    };

    expect(fn () => app(CreateOwner::class)->run($context, $journal))
        ->toThrow(RuntimeException::class, 'simulated master outage');

    app(Tenancy::class)->run($tenantId, function () {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        expect($connection->table('employees')->where('code', 'OWNER')->count())->toBe(1);
        expect($connection->table('employee_branches')->count())->toBe(1);
        expect($connection->table('employee_roles')->count())->toBe(1);
    }, TenantConnectionPurpose::Migrating);

    // Resume: the tenant half is found by code = 'OWNER' (D-24) and only the
    // master half completes - no second employee row is minted.
    $output = app(CreateOwner::class)->run($context, $journal);

    expect($output['mode'])->toBe('created');

    app(Tenancy::class)->run($tenantId, function () {
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        expect($connection->table('employees')->where('code', 'OWNER')->count())->toBe(1);
        expect($connection->table('employee_branches')->count())->toBe(1);
        expect($connection->table('employee_roles')->count())->toBe(1);
    }, TenantConnectionPurpose::Migrating);
});

it('does not re-print the password on a resume that finds the user already created', function () {
    [$context, $journal] = createOwnerFixture('TEN004');

    $first = app(CreateOwner::class)->run($context, $journal);
    $announcementAfterFirst = app(OwnerCredentialAnnouncement::class);
    expect($announcementAfterFirst->mode())->toBe('created')
        ->and($announcementAfterFirst->password())->toBeString();

    $second = app(CreateOwner::class)->run($context, $journal);

    expect($second['user_id'])->toBe($first['user_id'])
        ->and($second['mode'])->toBe('created');

    $announcementAfterSecond = app(OwnerCredentialAnnouncement::class);
    expect($announcementAfterSecond->mode())->toBe('already_created')
        ->and($announcementAfterSecond->password())->toBeNull();

    expect(PlatformSeed::connection()->table('users')->where('id', $first['user_id'])->count())->toBe(1);
});

it('refuses before entry when the mapping status is not provisioning (Ruling 6)', function () {
    [$context, $journal, $tenantId] = createOwnerFixture('TEN005');

    DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->update(['status' => 'active']);

    expect(fn () => app(CreateOwner::class)->run($context, $journal))
        ->toThrow(PlatformRefused::class, 'provisioning');
});

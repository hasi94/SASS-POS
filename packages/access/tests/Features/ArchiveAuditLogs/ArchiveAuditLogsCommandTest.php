<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\ArchiveAuditLogs;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 8 - audit:archive, over the fixture fleet (Ruling 6, AC-8)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-29 00:00:00.000'));
});

afterEach(function () {
    tenancy()->current()->hasTenant() && tenancy()->leave();
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * One aged row, written directly into the named tenant's audit_logs.
 */
function archiveCommandSeedAgedRow(string $tenant): void
{
    AccessTenantFixture::switchTo($tenant);

    AccessSeed::connection()->table('audit_logs')->insert([
        'actor_type' => 'employee',
        'actor_id' => null,
        'actor_name' => null,
        'branch_id' => null,
        'module' => 'access',
        'action' => 'role_created',
        'subject_type' => 'role',
        'subject_id' => 'SUBJECT0000000000000000001',
        'before' => null,
        'after' => null,
        'changed_fields' => null,
        'ip' => null,
        'user_agent' => null,
        'request_id' => null,
        'created_at' => '2025-01-01 00:00:00.000',
    ]);

    tenancy()->leave();
}

it('archives both named tenants, one call each', function () {
    archiveCommandSeedAgedRow('tenant-a');
    archiveCommandSeedAgedRow('tenant-b');

    $exitCode = Artisan::call('audit:archive', ['--tenant' => ['tenant-a', 'tenant-b']]);

    expect($exitCode)->toBe(0);

    AccessTenantFixture::switchTo('tenant-a');
    expect(AccessSeed::connection()->table('audit_logs_archive')->count())->toBe(1);
    tenancy()->leave();

    AccessTenantFixture::switchTo('tenant-b');
    expect(AccessSeed::connection()->table('audit_logs_archive')->count())->toBe(1);
});

it('isolates one tenant s failure: the other is still archived, the exit code is 1, and the failure is logged', function () {
    archiveCommandSeedAgedRow('tenant-a');

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'audit_logs')
                && $context['tenant_id'] === 'tenant-unopenable';
        });

    $exitCode = Artisan::call('audit:archive', ['--tenant' => ['tenant-a', 'tenant-unopenable']]);

    expect($exitCode)->toBe(1);

    AccessTenantFixture::switchTo('tenant-a');
    expect(AccessSeed::connection()->table('audit_logs_archive')->count())->toBe(1);
});

it('limits the run to the named tenant, leaving the other fixture tenant untouched', function () {
    archiveCommandSeedAgedRow('tenant-a');
    archiveCommandSeedAgedRow('tenant-b');

    $exitCode = Artisan::call('audit:archive', ['--tenant' => ['tenant-a']]);

    expect($exitCode)->toBe(0);

    AccessTenantFixture::switchTo('tenant-a');
    expect(AccessSeed::connection()->table('audit_logs_archive')->count())->toBe(1);
    tenancy()->leave();

    AccessTenantFixture::switchTo('tenant-b');
    expect(AccessSeed::connection()->table('audit_logs_archive')->count())->toBe(0)
        ->and(AccessSeed::connection()->table('audit_logs')->count())->toBe(1);
});

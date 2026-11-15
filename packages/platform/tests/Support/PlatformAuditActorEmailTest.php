<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Support\PlatformAudit;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 4 - the actor_email snapshot (AC-13)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
});

/**
 * One `users` row, as a platform staff member, with every column this table
 * requires.
 */
function platformAuditSeedUser(string $email): string
{
    $id = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    PlatformSeed::connection()->table('users')->insert([
        'id' => $id,
        'name' => 'Seeded User',
        'email' => $email,
        'password_hash' => str_repeat('a', 60),
        'status' => 'active',
        'is_platform_staff' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

it('snapshots the actor s email at write time', function () {
    $userId = platformAuditSeedUser('operator@example.test');

    app(PlatformAudit::class)->write(
        PlatformSeed::connection(),
        'tenant_status_synced',
        'tenant',
        'subject-x',
        null,
        $userId,
        null,
        ['status' => 'active'],
    );

    $row = PlatformSeed::auditRows('tenant_status_synced')[0];

    expect($row['actor_email'])->toBe('operator@example.test')
        ->and($row['actor_id'])->toBe($userId)
        ->and($row['actor_type'])->toBe('user');
});

it('leaves the stored row unchanged after the user s email is updated', function () {
    $userId = platformAuditSeedUser('before@example.test');

    app(PlatformAudit::class)->write(
        PlatformSeed::connection(),
        'tenant_status_synced',
        'tenant',
        'subject-x',
        null,
        $userId,
        null,
        ['status' => 'active'],
    );

    PlatformSeed::connection()->table('users')->where('id', $userId)->update(['email' => 'after@example.test']);

    $row = PlatformSeed::auditRows('tenant_status_synced')[0];

    expect($row['actor_email'])->toBe('before@example.test');
});

it('gives null actor_email for a system write with no actor id', function () {
    app(PlatformAudit::class)->write(
        PlatformSeed::connection(),
        'tenant_status_synced',
        'tenant',
        'subject-x',
        null,
        null,
        null,
        ['status' => 'active'],
    );

    $row = PlatformSeed::auditRows('tenant_status_synced')[0];

    expect($row['actor_email'])->toBeNull()
        ->and($row['actor_type'])->toBe('system');
});

it('gives null actor_email for an actor id naming nobody', function () {
    app(PlatformAudit::class)->write(
        PlatformSeed::connection(),
        'tenant_status_synced',
        'tenant',
        'subject-x',
        null,
        (string) Str::ulid(),
        null,
        ['status' => 'active'],
    );

    $row = PlatformSeed::auditRows('tenant_status_synced')[0];

    expect($row['actor_email'])->toBeNull()
        ->and($row['actor_type'])->toBe('user');
});

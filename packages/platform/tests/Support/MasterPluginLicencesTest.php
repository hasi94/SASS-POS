<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Contracts\Platform\PluginLicences;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Support\MasterPluginLicences;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 6 - MasterPluginLicences (D-3, plan Ruling 9; AC-13, AC-16)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-06-01 00:00:00.000'));
});

it('gives the id of an active licence with a null valid_until', function () {
    $tenant = PlatformSeed::tenant();
    $licenceId = PlatformSeed::licence($tenant, 'plugin', 'active', ['subject' => 'fixture-echo']);

    expect(app(PluginLicences::class))->toBeInstanceOf(MasterPluginLicences::class)
        ->and(app(PluginLicences::class)->currentLicenceId($tenant, 'fixture-echo'))->toBe($licenceId);
});

it('gives the id of an active licence with a future valid_until', function () {
    $tenant = PlatformSeed::tenant();
    $licenceId = PlatformSeed::licence($tenant, 'plugin', 'active', [
        'subject' => 'fixture-echo',
        'valid_until' => '2027-01-01 00:00:00.000',
    ]);

    expect(app(PluginLicences::class)->currentLicenceId($tenant, 'fixture-echo'))->toBe($licenceId);
});

it('gives null for a past valid_until', function () {
    $tenant = PlatformSeed::tenant();
    PlatformSeed::licence($tenant, 'plugin', 'active', [
        'subject' => 'fixture-echo',
        'valid_until' => '2025-01-01 00:00:00.000',
    ]);

    expect(app(PluginLicences::class)->currentLicenceId($tenant, 'fixture-echo'))->toBeNull();
});

it('gives null for a revoked or suspended row', function (string $status) {
    $tenant = PlatformSeed::tenant();
    PlatformSeed::licence($tenant, 'plugin', $status, ['subject' => 'fixture-echo']);

    expect(app(PluginLicences::class)->currentLicenceId($tenant, 'fixture-echo'))->toBeNull();
})->with(['revoked', 'suspended']);

it('gives null for a future valid_from', function () {
    $tenant = PlatformSeed::tenant();
    PlatformSeed::licence($tenant, 'plugin', 'active', [
        'subject' => 'fixture-echo',
        'valid_from' => '2027-01-01 00:00:00.000',
    ]);

    expect(app(PluginLicences::class)->currentLicenceId($tenant, 'fixture-echo'))->toBeNull();
});

it('gives null when the row names a different plugin', function () {
    $tenant = PlatformSeed::tenant();
    PlatformSeed::licence($tenant, 'plugin', 'active', ['subject' => 'fixture-other']);

    expect(app(PluginLicences::class)->currentLicenceId($tenant, 'fixture-echo'))->toBeNull();
});

it('gives null for a different tenant`s row', function () {
    $tenantA = PlatformSeed::tenant('tenant-a');
    $tenantB = PlatformSeed::tenant('tenant-b');
    PlatformSeed::licence($tenantA, 'plugin', 'active', ['subject' => 'fixture-echo']);

    expect(app(PluginLicences::class)->currentLicenceId($tenantB, 'fixture-echo'))->toBeNull();
});

it('gives null for a platform-type row with the same subject', function () {
    $tenant = PlatformSeed::tenant();
    PlatformSeed::licence($tenant, 'platform', 'active', ['subject' => 'fixture-echo']);

    expect(app(PluginLicences::class)->currentLicenceId($tenant, 'fixture-echo'))->toBeNull();
});

it('prefers a current row over an expired one for the same plugin', function () {
    $tenant = PlatformSeed::tenant();
    PlatformSeed::licence($tenant, 'plugin', 'active', [
        'subject' => 'fixture-echo',
        'valid_until' => '2025-01-01 00:00:00.000',
    ]);
    $current = PlatformSeed::licence($tenant, 'plugin', 'active', [
        'subject' => 'fixture-echo',
        'valid_until' => '2027-01-01 00:00:00.000',
    ]);

    expect(app(PluginLicences::class)->currentLicenceId($tenant, 'fixture-echo'))->toBe($current);
});

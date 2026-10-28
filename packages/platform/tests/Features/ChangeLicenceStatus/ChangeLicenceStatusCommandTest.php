<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\ChangeLicenceStatus;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 AC-33, BUG-2 - platform:change-licence-status
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

it('changes the licence status and suspends the tenant with licence_lapsed', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $licenceId = PlatformSeed::licence($tenantId, 'platform', 'active');

    $exitCode = Artisan::call('platform:change-licence-status', [
        'licence' => $licenceId,
        'status' => 'revoked',
        '--reason' => 'owner request',
        '--actor' => 'op-1',
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('licence_lapsed');

    $licence = (array) PlatformSeed::connection()->table('licences')->where('id', $licenceId)->first();

    expect($licence['status'])->toBe('revoked')
        ->and($licence['revoked_reason'])->toBe('owner request')
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended');
});

it('exits non-zero and writes nothing for an unknown licence id', function () {
    $exitCode = Artisan::call('platform:change-licence-status', [
        'licence' => 'not-a-licence',
        'status' => 'revoked',
    ]);

    expect($exitCode)->not->toBe(0);
});

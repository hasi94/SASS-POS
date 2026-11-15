<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\ProvisionTenant;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 8: tenant:provision and AC-1's validation (D-25, D-28)
|--------------------------------------------------------------------------
|
| AC-1, AC-6, AC-11 (refusal half), D-15, D-28. Every case here is a refusal:
| a fresh invocation always fails one of AC-1's rules before any row is
| written, proven by asserting the run/step/tenant tables stay empty. The
| success path (a fresh run actually completing) is step 15's own
| end-to-end proof, once the real step classes this handler resolves by
| name exist.
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
});

/**
 * @return array<string, mixed>
 */
function validProvisionOptions(string $planId): array
{
    return [
        '--code' => 'TEN001',
        '--name' => 'Test Shop',
        '--country' => 'LK',
        '--timezone' => 'Asia/Colombo',
        '--currency' => 'LKR',
        '--business-type' => 'retail',
        '--owner-email' => 'owner@example.com',
        '--plan' => $planId,
    ];
}

function assertNothingWasWritten(): void
{
    expect(PlatformSeed::connection()->table('tenant_provisioning_runs')->count())->toBe(0)
        ->and(PlatformSeed::connection()->table('tenant_provisioning_steps')->count())->toBe(0)
        ->and(PlatformSeed::connection()->table('tenants')->count())->toBe(0);
}

it('refuses --resume combined with --abandon, with nothing written', function () {
    $exitCode = Artisan::call('tenant:provision', [
        '--resume' => '01J0000000000000000000000A',
        '--abandon' => '01J0000000000000000000000B',
    ]);
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('--resume')
        ->and($output)->toContain('--abandon')
        ->and($output)->toContain('may not be combined');

    assertNothingWasWritten();
});

it('refuses --resume combined with --reissue-owner-password, with nothing written (review round 2 R-3)', function () {
    $exitCode = Artisan::call('tenant:provision', [
        '--resume' => '01J0000000000000000000000A',
        '--reissue-owner-password' => '01J0000000000000000000000B',
    ]);
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('--reissue-owner-password')
        ->and($output)->toContain('may not be combined');

    assertNothingWasWritten();
});

it('--reissue-owner-password refuses a run that does not exist, through the same plumbing as --resume (review round 2 R-3)', function () {
    $exitCode = Artisan::call('tenant:provision', [
        '--reissue-owner-password' => '01J0000000000000000000000D',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('No provisioning run');
});

it('refuses --resume naming a run that does not exist', function () {
    $exitCode = Artisan::call('tenant:provision', [
        '--resume' => '01J0000000000000000000000A',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('No provisioning run');
});

dataset('required options', [
    'code' => ['code'],
    'name' => ['name'],
    'country' => ['country'],
    'timezone' => ['timezone'],
    'currency' => ['currency'],
    'business-type' => ['business-type'],
    'owner-email' => ['owner-email'],
    'plan' => ['plan'],
]);

it('refuses a missing required option, naming the field, with nothing written', function (string $field) {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    unset($options['--'.$field]);

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain($field);

    assertNothingWasWritten();
})->with('required options');

it('refuses a blank required option, naming the field, with nothing written', function (string $field) {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    $options['--'.$field] = '   ';

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain($field);

    assertNothingWasWritten();
})->with('required options');

it('refuses a --code that does not fit the tenant-part pattern', function () {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    $options['--code'] = '-TEN001';

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('--code');

    assertNothingWasWritten();
});

it('refuses a lower-case --country', function () {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    $options['--country'] = 'lk';

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('--country');

    assertNothingWasWritten();
});

it('refuses a --currency that is not exactly three upper-case letters', function () {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    $options['--currency'] = 'lkr';

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('--currency');

    assertNothingWasWritten();
});

it('refuses a --timezone that DateTimeZone does not recognise', function () {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    $options['--timezone'] = 'Nowhere/Nothing';

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('--timezone');

    assertNothingWasWritten();
});

it('refuses a --business-type outside retail, restaurant, both', function () {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    $options['--business-type'] = 'wholesale';

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('--business-type');

    assertNothingWasWritten();
});

it('refuses an --owner-email that is not a valid address', function () {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    $options['--owner-email'] = 'not-an-email';

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('--owner-email');

    assertNothingWasWritten();
});

it('refuses an unknown --plan id', function () {
    $options = validProvisionOptions('not-a-plan-id');

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('plan');

    assertNothingWasWritten();
});

it('refuses a soft-deleted --plan id', function () {
    $planId = PlatformSeed::plan(['deleted_at' => app(RunnerClock::class)->timestamp()]);
    $options = validProvisionOptions($planId);

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('plan');

    assertNothingWasWritten();
});

it('refuses an inactive --plan id', function () {
    $planId = PlatformSeed::plan(['is_active' => false]);
    $options = validProvisionOptions($planId);

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('plan');

    assertNothingWasWritten();
});

it('refuses a --branch-name over 100 characters (B-12), through DefaultBranch::validate()', function () {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    $options['--branch-name'] = str_repeat('x', 101);

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0);

    assertNothingWasWritten();
});

it('refuses a --branch-code that fails OrgCode::normalise()', function () {
    $planId = PlatformSeed::plan();
    $options = validProvisionOptions($planId);
    $options['--branch-code'] = 'TOO-LONG-A-CODE';

    $exitCode = Artisan::call('tenant:provision', $options);

    expect($exitCode)->not->toBe(0);

    assertNothingWasWritten();
});

it('--abandon refuses a run that does not exist, through the same plumbing as --resume', function () {
    $exitCode = Artisan::call('tenant:provision', [
        '--abandon' => '01J0000000000000000000000C',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('No provisioning run');
});

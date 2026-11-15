<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Support\PlatformAudit;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 4 - PlatformAudit's real request_id (closes FP-323, AC-12)
|--------------------------------------------------------------------------
|
| Both cases here run with no HTTP request behind them - a console command
| and a direct package-test handler call - and both must carry null, never
| a freshly minted, uncorrelated ULID. The HTTP half (two rows sharing one
| response's id) lives in the host's AuditRequestIdHttpTest, which is the
| only place a real request exists to assert against.
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
});

it('gives null request_id for a console-triggered write (platform:enforce-deadlines)', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'past_due');
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'past_due', ['past_due_at' => '2026-01-01 00:00:00.000']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-08 00:00:00.000'));

    expect(Artisan::call('platform:enforce-deadlines'))->toBe(0);

    $rows = PlatformSeed::auditRows('tenant_status_synced');

    expect($rows)->not->toBe([])
        ->and($rows[0]['request_id'])->toBeNull();
});

it('gives null request_id for a direct handler-level write in a package test', function () {
    /** @var PlatformAudit $audit */
    $audit = app(PlatformAudit::class);

    $audit->write(
        PlatformSeed::connection(),
        'tenant_status_synced',
        'tenant',
        'subject-x',
        null,
        null,
        null,
        ['status' => 'active'],
    );

    $rows = PlatformSeed::auditRows('tenant_status_synced');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['request_id'])->toBeNull();
});

it('refuses an undeclared action before inserting (AC-19, AC-20)', function () {
    /** @var PlatformAudit $audit */
    $audit = app(PlatformAudit::class);

    expect(fn () => $audit->write(
        PlatformSeed::connection(),
        'no_such_action',
        'tenant',
        'subject-x',
        null,
        null,
        null,
        null,
    ))->toThrow(LogicException::class);

    expect(PlatformSeed::connection()->table('platform_audit_logs')->count())->toBe(0);
});

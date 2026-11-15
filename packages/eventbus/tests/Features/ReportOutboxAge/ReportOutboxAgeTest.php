<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Features\ReportOutboxAge;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Eventbus\Features\ReportOutboxAge\ReportOutboxAge;
use Flexipos\Eventbus\Features\ReportOutboxAge\ReportOutboxAgeHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 8 - `php artisan eventbus:outbox-age` (Ruling 10, AC-32)
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/../../Support/eventbus-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
});

afterEach(function () {
    if (tenancy()->current()->hasTenant()) {
        tenancy()->leave();
    }

    AccessTenantFixture::forget();
});

it('AC-32: reports the age in seconds of the oldest pending row', function () {
    $clock = eventbusClockAt('2026-01-01 00:01:30');
    eventbusEnterTenant('tenant-a');

    DB::connection(AccessTenantFixture::connectionName())->table('outbox_events')->insert([
        'id' => (string) Str::ulid(),
        'type' => 'fixture.pinged',
        'version' => 1,
        'branch_id' => null,
        'aggregate_type' => 'fixture',
        'aggregate_id' => (string) Str::ulid(),
        'payload' => null,
        'occurred_at' => '2026-01-01 00:00:00.000',
        'correlation_id' => null,
        'causation_id' => null,
        'actor' => json_encode(['type' => 'system', 'id' => null, 'name' => null]),
        'status' => 'pending',
        'attempts' => 0,
    ]);

    $seconds = app(ReportOutboxAgeHandler::class)->handle(new ReportOutboxAge);

    expect($seconds)->toBe(90);
});

it('AC-32: with nothing pending, the handler reports null and the command prints "none"', function () {
    eventbusEnterTenant('tenant-a');

    $seconds = app(ReportOutboxAgeHandler::class)->handle(new ReportOutboxAge);

    expect($seconds)->toBeNull();

    tenancy()->leave();

    $exit = Artisan::call('eventbus:outbox-age', ['--tenant' => ['tenant-a']]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('none');
});

it('AC-32: --tenant (repeatable) visits exactly the named tenants', function () {
    $exit = Artisan::call('eventbus:outbox-age', ['--tenant' => ['tenant-a', 'tenant-b']]);

    // Artisan::output() drains a BufferedOutput - read it once.
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('tenant-a');
    expect($output)->toContain('tenant-b');
});

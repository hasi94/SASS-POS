<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Features\RelayOutbox;

use Carbon\CarbonInterval;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Tests\Support\FixtureEventConsumerProvider;
use Flexipos\Eventbus\Tests\Support\PingedConsumer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 6 - `php artisan eventbus:relay` (Ruling 6)
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/../../Support/eventbus-fixture.php';

uses(TestCase::class);

afterEach(function () {
    if (tenancy()->current()->hasTenant()) {
        tenancy()->leave();
    }

    AccessTenantFixture::forget();
});

function eventbusOneRowStatus(): ?string
{
    $row = DB::connection(AccessTenantFixture::connectionName())->table('outbox_events')->first();

    return $row === null ? null : (string) $row->status;
}

it('--once with no --tenant option visits the whole fleet TenantFleet names, not a fixed pair', function () {
    AccessTenantFixture::boot();
    eventbusEnterTenant('tenant-a');
    eventbusInsertOutboxRow();
    tenancy()->leave();

    eventbusEnterTenant('tenant-b');
    eventbusInsertOutboxRow();
    tenancy()->leave();

    // The kernel fixture's own serving set also names tenant-unopenable and
    // tenant-wrong-driver (same precedent ArchiveAuditLogsCommandTest uses
    // for its own "one tenant fails" case): AccessTenantFixture::mapAt()
    // never raises their schema version, so visiting the whole fleet with
    // no --tenant option fails on them and the exit code is 1. What this
    // proves is that no option really does visit every tenant the fleet
    // names rather than a hard-coded pair: both real tenants are still
    // relayed regardless.
    $exit = Artisan::call('eventbus:relay', ['--once' => true]);

    expect($exit)->toBe(1);

    eventbusEnterTenant('tenant-a');
    expect(eventbusOneRowStatus())->toBe('published');
    tenancy()->leave();

    eventbusEnterTenant('tenant-b');
    expect(eventbusOneRowStatus())->toBe('published');
    tenancy()->leave();
});

it('--tenant limits the run to the named tenants', function () {
    AccessTenantFixture::boot();
    eventbusEnterTenant('tenant-a');
    eventbusInsertOutboxRow();
    tenancy()->leave();

    eventbusEnterTenant('tenant-b');
    eventbusInsertOutboxRow();
    tenancy()->leave();

    Artisan::call('eventbus:relay', ['--once' => true, '--tenant' => ['tenant-a']]);

    eventbusEnterTenant('tenant-a');
    expect(eventbusOneRowStatus())->toBe('published');
    tenancy()->leave();

    eventbusEnterTenant('tenant-b');
    expect(eventbusOneRowStatus())->toBe('pending');
    tenancy()->leave();
});

it('a failing tenant is logged, the others are relayed, and the exit code is 1', function () {
    AccessTenantFixture::boot();
    eventbusEnterTenant('tenant-a');
    eventbusInsertOutboxRow();
    tenancy()->leave();

    eventbusEnterTenant('tenant-b');
    eventbusInsertOutboxRow();
    tenancy()->leave();

    // "no-such-tenant" names no mapping row: Tenancy::run() throws before
    // this command's try/catch ever reaches the handler, which is a
    // simpler, deterministic way to force one tenant's pass to fail than
    // exhausting five real publish attempts.
    $exit = Artisan::call('eventbus:relay', [
        '--once' => true,
        '--tenant' => ['tenant-a', 'no-such-tenant', 'tenant-b'],
    ]);

    expect($exit)->toBe(1);

    eventbusEnterTenant('tenant-a');
    expect(eventbusOneRowStatus())->toBe('published');
    tenancy()->leave();

    eventbusEnterTenant('tenant-b');
    expect(eventbusOneRowStatus())->toBe('published');
    tenancy()->leave();
});

it('ensures every declared consumer group before the first pass', function () {
    AccessTenantFixture::boot();

    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    Artisan::call('eventbus:relay', ['--once' => true, '--tenant' => []]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');

    // ensureGroup() was called for the declared group before any pass, so a
    // message published afterwards is visible to it with no further setup.
    $envelope = new EventEnvelope(
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'tenant-a',
        PingedConsumer::EVENT_TYPE,
        1,
        'fixture',
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        null,
        null,
        '2026-01-01 00:00:00.000',
        null,
        null,
        EventActor::system(),
    );

    $bus->publish($stream, $envelope);

    expect($bus->read($stream, PingedConsumer::GROUP, 'c1', 10))->toHaveCount(1);
});

it('sleeps poll_seconds between passes with --max-passes, and never with --once', function () {
    Sleep::fake();

    Artisan::call('eventbus:relay', ['--once' => true, '--tenant' => [], '--sleep' => 1]);
    Sleep::assertNeverSlept();

    Artisan::call('eventbus:relay', ['--tenant' => [], '--max-passes' => 2, '--sleep' => 1]);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->equalTo(CarbonInterval::second(1)), times: 1);
});

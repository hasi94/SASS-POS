<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Features\ReplayDeadLetter;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Tests\Support\FixtureEventConsumerProvider;
use Flexipos\Eventbus\Tests\Support\PingedConsumer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 8 - `php artisan eventbus:replay` (Ruling 10, AC-31)
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/../../Support/eventbus-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    eventbusEnterTenant('tenant-a');
    eventbusCreatePingsTable();
    tenancy()->leave();
    PingedConsumer::reset();
});

afterEach(function () {
    if (tenancy()->current()->hasTenant()) {
        tenancy()->leave();
    }

    AccessTenantFixture::forget();
});

it('AC-31: replays a dead letter, and the fixture group handles it', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $deadStream = (string) config('eventbus.dead_letter_stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);

    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    $delivered = $bus->read($stream, PingedConsumer::GROUP, 'c1', 10);
    $deadId = $bus->deadLetter($deadStream, $delivered[0], PingedConsumer::GROUP, 'poison');

    $exit = Artisan::call('eventbus:replay', ['dead-id' => $deadId]);

    expect($exit)->toBe(0);

    Artisan::call('eventbus:consume', ['--group' => [PingedConsumer::GROUP], '--once' => true]);

    eventbusEnterTenant('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->table('fixture_pings')->count())->toBe(1);
});

it('AC-31 note: a replay re-enters the shared stream, so every existing group sees it again - a replay cannot target one group', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $deadStream = (string) config('eventbus.dead_letter_stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->ensureGroup($stream, 'second-group');

    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    // second-group already handled it before the dead-letter move.
    $bus->read($stream, 'second-group', 'c1', 10);

    $delivered = $bus->read($stream, PingedConsumer::GROUP, 'c1', 10);
    $deadId = $bus->deadLetter($deadStream, $delivered[0], PingedConsumer::GROUP, 'poison');

    Artisan::call('eventbus:replay', ['dead-id' => $deadId]);

    // second-group sees the replayed message too, even though it already
    // read the original and was not the group the dead letter came from -
    // proving the replay lands on the shared stream, not on one group's
    // own view of it. A real consumer behind this group would then no-op
    // by its own idempotency (ConsumeEventsTest already proves that half).
    expect($bus->read($stream, 'second-group', 'c1', 10))->toHaveCount(1);
});

it('AC-31: an unknown dead-letter id refuses with exit 1', function () {
    $exit = Artisan::call('eventbus:replay', ['dead-id' => 'no-such-id']);

    expect($exit)->toBe(1);
});

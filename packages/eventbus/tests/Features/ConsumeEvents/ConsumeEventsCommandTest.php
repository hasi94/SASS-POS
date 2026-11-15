<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Features\ConsumeEvents;

use Carbon\CarbonInterval;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Events\EventConsumer;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Tests\Support\FixtureEventConsumerProvider;
use Flexipos\Eventbus\Tests\Support\PingedConsumer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use LogicException;
use Tests\TestCase;

/**
 * A second, do-nothing consumer group, declared only to prove `--group`
 * narrows which declared group a pass visits - not to prove anything
 * about its own handling.
 */
final class ConsumeEventsCommandOtherConsumer implements EventConsumer
{
    public function group(): string
    {
        return 'fixture.other-group';
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return [];
    }

    public function handle(EventEnvelope $event): void {}
}

/*
|--------------------------------------------------------------------------
| T-018 step 7 - `php artisan eventbus:consume` (Ruling 7)
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

it('--group limits the run to the named groups', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class, ConsumeEventsCommandOtherConsumer::class]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    $exit = Artisan::call('eventbus:consume', ['--group' => ['fixture.other-group'], '--once' => true]);

    expect($exit)->toBe(0);
    // The named group's own pass ran (and saw nothing, its stream is
    // empty), but PingedConsumer::GROUP was never visited: the message
    // published for it is still unread.
    expect($bus->read($stream, PingedConsumer::GROUP, 'probe', 10))->toHaveCount(1);
});

it('--once consumes exactly the declared group it is given', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    $exit = Artisan::call('eventbus:consume', ['--group' => [PingedConsumer::GROUP], '--once' => true]);

    expect($exit)->toBe(0);

    eventbusEnterTenant('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->table('fixture_pings')->count())->toBe(1);
});

it('sleeps poll_seconds between passes with --max-passes, and never with --once', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    Sleep::fake();

    Artisan::call('eventbus:consume', ['--group' => [PingedConsumer::GROUP], '--once' => true, '--sleep' => 1]);
    Sleep::assertNeverSlept();

    Artisan::call('eventbus:consume', ['--group' => [PingedConsumer::GROUP], '--max-passes' => 2, '--sleep' => 1]);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->equalTo(CarbonInterval::second(1)), times: 1);
});

it('W-5: defaults --sleep from eventbus.consumer.poll_seconds when --sleep is not given', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);
    config(['eventbus.consumer.poll_seconds' => 9]);

    Sleep::fake();

    Artisan::call('eventbus:consume', ['--group' => [PingedConsumer::GROUP], '--max-passes' => 2]);

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->equalTo(CarbonInterval::second(9)), times: 1);
});

it('S-5: a non-positive --sleep option throws rather than reaching Sleep::for()', function () {
    expect(fn () => Artisan::call('eventbus:consume', ['--group' => [PingedConsumer::GROUP], '--sleep' => -1]))
        ->toThrow(LogicException::class);
});

it('S-5: a non-positive eventbus.consumer.poll_seconds throws when --sleep is not given', function () {
    config(['eventbus.consumer.poll_seconds' => 0]);

    expect(fn () => Artisan::call('eventbus:consume', ['--group' => [PingedConsumer::GROUP]]))
        ->toThrow(LogicException::class);
});

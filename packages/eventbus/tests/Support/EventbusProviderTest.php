<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Eventbus\Bus\RedisStreamsEventBus;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\EventbusServiceProvider;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 1 - the provider is loaded, the shipped defaults are what the
| relay and the consumer runner read, and booting connects to nothing
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('is loaded', function () {
    expect(app()->getLoadedProviders())->toHaveKey(EventbusServiceProvider::class);
});

it('ships the documented relay and consumer defaults', function () {
    expect(config('eventbus.relay.max_attempts'))->toBe(5)
        ->and(config('eventbus.consumer.max_deliveries'))->toBe(5)
        ->and(config('eventbus.relay.batch_size'))->toBe(100)
        ->and(config('eventbus.consumer.batch_size'))->toBe(10)
        ->and(config('eventbus.stream'))->toBe('flexipos:events')
        ->and(config('eventbus.dead_letter_stream'))->toBe('flexipos:events:dead');
});

it('resolves the Redis adapter with no connection attempt: booting never connects, only first use does', function () {
    config(['eventbus.driver' => 'redis']);

    expect(app()->resolved(RedisStreamsEventBus::class))->toBeFalse();

    // Building the object touches only its own constructor - the Redis
    // Factory, the connection name and the max length - never
    // Factory::connection(), which is where a real network attempt would
    // happen (Ruling 8).
    $bus = app(EventBus::class);

    expect($bus)->toBeInstanceOf(RedisStreamsEventBus::class);
});

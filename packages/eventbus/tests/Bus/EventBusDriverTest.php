<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Bus;

use Flexipos\Eventbus\Bus\EventBusDriver;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Exceptions\EventBusDriverNotPermitted;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 3 - which EventBus implementation serves this process
| (Ruling 8, AC-1, AC-4)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('resolves the in-memory stand-in under testing', function () {
    config(['eventbus.driver' => 'memory']);

    expect(EventBusDriver::resolve(app()))->toBeInstanceOf(InMemoryEventBus::class);
});

it('refuses the stand-in outside a local environment', function () {
    config(['eventbus.driver' => 'memory']);

    app()->detectEnvironment(fn () => 'production');

    expect(fn () => EventBusDriver::resolve(app()))->toThrow(EventBusDriverNotPermitted::class);
});

it('refuses an unknown driver', function () {
    config(['eventbus.driver' => 'kafka']);

    expect(fn () => EventBusDriver::resolve(app()))->toThrow(EventBusDriverNotPermitted::class);
});

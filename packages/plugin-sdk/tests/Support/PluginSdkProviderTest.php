<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\PluginSdk\PluginSdkServiceProvider;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 1 - the provider is loaded and the shipped defaults are what
| the gateway and the breaker read
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('is loaded', function () {
    expect(app()->getLoadedProviders())->toHaveKey(PluginSdkServiceProvider::class);
});

it('ships the documented defaults', function () {
    expect(config('plugins.breaker.threshold'))->toBe(5)
        ->and(config('plugins.timeout_ms'))->toBe(500)
        ->and(config('plugins.core_version'))->toBe('0.1.0');
});

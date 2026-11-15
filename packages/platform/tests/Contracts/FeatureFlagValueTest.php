<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Contracts;

use Flexipos\Contracts\Platform\FeatureFlagValue;

/*
|--------------------------------------------------------------------------
| T-016 step 1 - FeatureFlagValue::isEnabled, Ruling 13's full table
|--------------------------------------------------------------------------
*/

it('is disabled only for false, null, empty string, empty array or empty object', function () {
    expect((new FeatureFlagValue('k', false, 'default'))->isEnabled())->toBeFalse()
        ->and((new FeatureFlagValue('k', null, 'default'))->isEnabled())->toBeFalse()
        ->and((new FeatureFlagValue('k', '', 'default'))->isEnabled())->toBeFalse()
        ->and((new FeatureFlagValue('k', [], 'default'))->isEnabled())->toBeFalse()
        ->and((new FeatureFlagValue('k', json_decode('{}', true), 'default'))->isEnabled())->toBeFalse();
});

it('is enabled for true, zero, a non-empty string, and a non-empty array', function () {
    expect((new FeatureFlagValue('k', true, 'default'))->isEnabled())->toBeTrue()
        ->and((new FeatureFlagValue('k', 0, 'default'))->isEnabled())->toBeTrue()
        ->and((new FeatureFlagValue('k', 'x', 'default'))->isEnabled())->toBeTrue()
        ->and((new FeatureFlagValue('k', ['cap' => 5], 'default'))->isEnabled())->toBeTrue();
});

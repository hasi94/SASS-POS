<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Contracts;

use Flexipos\Contracts\Plugins\HookMode;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 2 - the five modes, in D-1's order (plan Ruling 1)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('holds exactly five values, in the D-1 order', function () {
    expect(array_map(fn (HookMode $mode): string => $mode->value, HookMode::cases()))
        ->toBe(['add', 'before', 'after', 'filter', 'override']);
});

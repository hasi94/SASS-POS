<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Contracts;

use Flexipos\Contracts\Plugins\HookOutcome;
use Flexipos\Contracts\Plugins\HookVeto;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 2 - vetoed()
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('vetoed() is false with no veto', function () {
    $outcome = new HookOutcome('fixture.echo', null, [], null, null, [], [], []);

    expect($outcome->vetoed())->toBeFalse();
});

it('vetoed() is true with a veto', function () {
    $outcome = new HookOutcome('fixture.echo', new HookVeto('fixture-echo', 'no'), [], null, null, [], [], []);

    expect($outcome->vetoed())->toBeTrue();
});

<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Contracts;

use Flexipos\Contracts\Plugins\HookMode;
use Flexipos\Contracts\Plugins\HookPoint;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 2 - the name pattern, the 60-character limit, isOverridable()
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('accepts a dotted lower-case name', function () {
    expect(preg_match(HookPoint::NAME_PATTERN, 'fixture.echo'))->toBe(1)
        ->and(preg_match(HookPoint::NAME_PATTERN, 'fixture.plain'))->toBe(1);
});

it('refuses a name with no dot, upper case, or a leading digit', function () {
    expect(preg_match(HookPoint::NAME_PATTERN, 'fixture'))->toBe(0)
        ->and(preg_match(HookPoint::NAME_PATTERN, 'Fixture.Echo'))->toBe(0)
        ->and(preg_match(HookPoint::NAME_PATTERN, '1fixture.echo'))->toBe(0);
});

it('rejects a name over 60 characters by length, not by the pattern', function () {
    $name = 'fixture.'.str_repeat('a', 60);

    expect(strlen($name))->toBeGreaterThan(60)
        ->and(preg_match(HookPoint::NAME_PATTERN, $name))->toBe(1);
});

it('isOverridable() is true only when override is among the modes', function () {
    $overridable = new HookPoint('fixture.echo', [HookMode::Before, HookMode::Override]);
    $notOverridable = new HookPoint('fixture.plain', [HookMode::Before, HookMode::Add]);

    expect($overridable->isOverridable())->toBeTrue()
        ->and($notOverridable->isOverridable())->toBeFalse();
});

it('accepts() tells whether a mode is declared', function () {
    $point = new HookPoint('fixture.echo', [HookMode::Add, HookMode::Before]);

    expect($point->accepts(HookMode::Add))->toBeTrue()
        ->and($point->accepts(HookMode::Override))->toBeFalse();
});

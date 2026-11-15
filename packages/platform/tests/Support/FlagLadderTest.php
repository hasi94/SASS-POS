<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Platform\Support\FlagLadder;

/*
|--------------------------------------------------------------------------
| T-016 step 3 - FlagLadder::resolve(), D-5/D-9 (AC-17 to AC-20)
|--------------------------------------------------------------------------
*/

it('falls back to the global default when neither the plan nor the tenant has a row', function () {
    expect(FlagLadder::resolve('k', true, false, null, false, null)->source)->toBe('default')
        ->and(FlagLadder::resolve('k', true, false, null, false, null)->isEnabled())->toBeTrue()
        ->and(FlagLadder::resolve('k', false, false, null, false, null)->isEnabled())->toBeFalse();
});

it('a plan row overrides the default, including a stored null meaning disabled', function () {
    $value = FlagLadder::resolve('k', true, true, false, false, null);

    expect($value->source)->toBe('plan')
        ->and($value->isEnabled())->toBeFalse();

    $nullRow = FlagLadder::resolve('k', true, true, null, false, null);

    expect($nullRow->source)->toBe('plan')
        ->and($nullRow->isEnabled())->toBeFalse();
});

it('falls back to the default when the plan changes to one without the row', function () {
    expect(FlagLadder::resolve('k', true, false, null, false, null)->source)->toBe('default');
});

it('a tenant override beats both the plan value and the default, in one fixture', function () {
    $value = FlagLadder::resolve('k', false, true, false, true, true);

    expect($value->source)->toBe('tenant')
        ->and($value->isEnabled())->toBeTrue();
});

it('a JSON object plan value resolves as-is and reads enabled', function () {
    $value = FlagLadder::resolve('k', false, true, ['cap' => 5], false, null);

    expect($value->value)->toBe(['cap' => 5])
        ->and($value->isEnabled())->toBeTrue();
});

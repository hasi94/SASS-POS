<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Manifest;

use Flexipos\PluginSdk\Manifest\InvalidVersionConstraint;
use Flexipos\PluginSdk\Manifest\VersionConstraint;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 5 - the narrow version-constraint grammar (plan Ruling 3)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('matches an exact version', function () {
    $c = VersionConstraint::parse('1.2.3');

    expect($c->satisfiedBy('1.2.3'))->toBeTrue()
        ->and($c->satisfiedBy('1.2.4'))->toBeFalse();
});

it('matches a caret range', function () {
    $c = VersionConstraint::parse('^1.2.3');

    expect($c->satisfiedBy('1.2.3'))->toBeTrue()
        ->and($c->satisfiedBy('1.9.0'))->toBeTrue()
        ->and($c->satisfiedBy('1.2.2'))->toBeFalse()
        ->and($c->satisfiedBy('2.0.0'))->toBeFalse();
});

it('narrows a 0.x caret range to the next minor', function () {
    $c = VersionConstraint::parse('^0.2.3');

    expect($c->satisfiedBy('0.2.3'))->toBeTrue()
        ->and($c->satisfiedBy('0.2.9'))->toBeTrue()
        ->and($c->satisfiedBy('0.3.0'))->toBeFalse()
        ->and($c->satisfiedBy('0.2.2'))->toBeFalse();
});

it('matches a tilde range to the next minor', function () {
    $c = VersionConstraint::parse('~1.2');

    expect($c->satisfiedBy('1.2.0'))->toBeTrue()
        ->and($c->satisfiedBy('1.2.9'))->toBeTrue()
        ->and($c->satisfiedBy('1.3.0'))->toBeFalse()
        ->and($c->satisfiedBy('1.1.9'))->toBeFalse();
});

it('matches an OR of alternatives', function () {
    $c = VersionConstraint::parse('1.0.0 || ^2.0.0');

    expect($c->satisfiedBy('1.0.0'))->toBeTrue()
        ->and($c->satisfiedBy('2.5.0'))->toBeTrue()
        ->and($c->satisfiedBy('1.5.0'))->toBeFalse();
});

it('ANDs terms separated by a space', function () {
    $c = VersionConstraint::parse('>=1.0.0 <2.0.0');

    expect($c->satisfiedBy('1.5.0'))->toBeTrue()
        ->and($c->satisfiedBy('2.0.0'))->toBeFalse()
        ->and($c->satisfiedBy('0.9.0'))->toBeFalse();
});

it('matches anything for a bare star', function () {
    $c = VersionConstraint::parse('*');

    expect($c->satisfiedBy('0.0.1'))->toBeTrue()
        ->and($c->satisfiedBy('9.9.9'))->toBeTrue();
});

it('refuses every malformed form', function (string $expression) {
    expect(fn () => VersionConstraint::parse($expression))->toThrow(InvalidVersionConstraint::class);
})->with([
    '',
    'not-a-version',
    '>=',
    '1.2.3.4',
    '>>1.0.0',
]);

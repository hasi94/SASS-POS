<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Gateway;

use Flexipos\Contracts\Plugins\DeclaresHookPoints;
use Flexipos\Contracts\Plugins\HookMode;
use Flexipos\Contracts\Plugins\HookPoint;
use Flexipos\PluginSdk\Gateway\HookPointCatalogue;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 5 - the hook point catalogue (plan Ruling 4)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

final class HookPointCatalogueDuplicateProvider extends ServiceProvider implements DeclaresHookPoints
{
    public function register(): void {}

    public function hookPoints(): array
    {
        return [new HookPoint('fixture.echo', [HookMode::Add])];
    }
}

final class HookPointCatalogueBadNameProvider extends ServiceProvider implements DeclaresHookPoints
{
    public function register(): void {}

    public function hookPoints(): array
    {
        return [new HookPoint('NoDot', [HookMode::Add])];
    }
}

it('sees a fixture provider`s points with no memo', function () {
    app()->register(new FixtureHookPointProvider(app()), true);

    $catalogue = app(HookPointCatalogue::class);

    expect(array_map(fn (HookPoint $p): string => $p->name, $catalogue->all()))
        ->toContain('fixture.echo', 'fixture.plain');

    expect($catalogue->find('fixture.echo')?->isOverridable())->toBeTrue();
});

it('refuses a duplicate point name', function () {
    app()->register(new FixtureHookPointProvider(app()), true);
    app()->register(new HookPointCatalogueDuplicateProvider(app()), true);

    $catalogue = app(HookPointCatalogue::class);

    expect(fn () => $catalogue->all())->toThrow(LogicException::class);
});

it('refuses a point name outside the required shape', function () {
    app()->register(new HookPointCatalogueBadNameProvider(app()), true);

    $catalogue = app(HookPointCatalogue::class);

    expect(fn () => $catalogue->all())->toThrow(LogicException::class);
});

<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Contracts\Plugins\DeclaresHookPoints;
use Flexipos\Contracts\Plugins\HookMode;
use Flexipos\Contracts\Plugins\HookPoint;
use Illuminate\Support\ServiceProvider;

/**
 * The two test-only hook points every T-019 fixture test registers against
 * (plan D-7, step 5): `fixture.echo` accepts all five modes (it is the
 * point `FixtureEcho` registers against for the proof of pipeline);
 * `fixture.plain` accepts everything except `override`, so AC-3's refusal
 * has a point to be refused against. `fixture.narrow` accepts only
 * `before`, so `modeNotAccepted` has a point to be refused against that
 * is not also caught by `overrideNotPermitted`.
 */
final class FixtureHookPointProvider extends ServiceProvider implements DeclaresHookPoints
{
    public function register(): void {}

    /**
     * @return list<HookPoint>
     */
    public function hookPoints(): array
    {
        return [
            new HookPoint('fixture.echo', [
                HookMode::Add, HookMode::Before, HookMode::Filter, HookMode::Override, HookMode::After,
            ]),
            new HookPoint('fixture.plain', [
                HookMode::Add, HookMode::Before, HookMode::Filter, HookMode::After,
            ]),
            new HookPoint('fixture.narrow', [
                HookMode::Before,
            ]),
        ];
    }
}

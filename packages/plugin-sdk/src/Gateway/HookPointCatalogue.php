<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

use Flexipos\Contracts\Plugins\DeclaresHookPoints;
use Flexipos\Contracts\Plugins\HookPoint;
use Illuminate\Foundation\Application;
use LogicException;

/**
 * Every declared hook point this process's booted providers have ever said
 * they own (T-019 plan Ruling 4).
 *
 * The same shape as `Flexipos\Eventbus\Support\ConsumerRegistry`: it asks
 * the container which registered providers implement
 * {@see DeclaresHookPoints} and **holds no memo**, so a provider a test
 * registers after construction is seen at once.
 */
final class HookPointCatalogue
{
    public function __construct(private readonly Application $app) {}

    /**
     * @return list<HookPoint>
     *
     * @throws LogicException a duplicate point name, or a name outside
     *                        {@see HookPoint::NAME_PATTERN}
     */
    public function all(): array
    {
        $byName = [];

        foreach ($this->providers() as $provider) {
            foreach ($provider->hookPoints() as $point) {
                if (preg_match(HookPoint::NAME_PATTERN, $point->name) !== 1) {
                    throw new LogicException("Hook point name [{$point->name}] does not match the required shape.");
                }

                if (isset($byName[$point->name])) {
                    throw new LogicException("Hook point [{$point->name}] is declared more than once.");
                }

                $byName[$point->name] = $point;
            }
        }

        return array_values($byName);
    }

    public function find(string $name): ?HookPoint
    {
        foreach ($this->all() as $point) {
            if ($point->name === $name) {
                return $point;
            }
        }

        return null;
    }

    /**
     * @return list<DeclaresHookPoints>
     */
    private function providers(): array
    {
        $providers = [];

        foreach ($this->app->getProviders(DeclaresHookPoints::class) as $provider) {
            if ($provider instanceof DeclaresHookPoints) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}

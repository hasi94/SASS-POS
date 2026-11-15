<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Support;

use Flexipos\Contracts\Events\DeclaresEventConsumers;
use Flexipos\Contracts\Events\EventConsumer;
use Illuminate\Foundation\Application;
use LogicException;

/**
 * Every declared event consumer this process's booted providers have ever
 * said they run (T-018 Ruling 7), by consumer group.
 *
 * **The same shape as `Flexipos\Access\Support\AuditCatalogue`, for the same
 * reason.** It asks the container which registered providers implement
 * {@see DeclaresEventConsumers}, holds no memo, and is not bound in the
 * container - `Illuminate\Foundation\Application` is always
 * self-resolvable, so this class is built by reflection wherever it is
 * asked for.
 */
final class ConsumerRegistry
{
    private const GROUP_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    public function __construct(private readonly Application $app) {}

    /**
     * Every declared consumer group, sorted.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        $groups = array_keys($this->byGroup());

        sort($groups);

        return $groups;
    }

    /**
     * @throws LogicException when no provider declared this group
     */
    public function resolve(string $group): EventConsumer
    {
        $byGroup = $this->byGroup();

        if (! isset($byGroup[$group])) {
            throw new LogicException("No event consumer is declared for group [{$group}].");
        }

        return $byGroup[$group];
    }

    /**
     * @return array<string, EventConsumer>
     *
     * @throws LogicException a duplicate group name, a group name outside
     *                        the required shape, or a class that does not
     *                        implement EventConsumer
     */
    private function byGroup(): array
    {
        $byGroup = [];

        foreach ($this->providers() as $provider) {
            foreach ($provider->eventConsumers() as $consumerClass) {
                if (! self::implementsEventConsumer($consumerClass)) {
                    throw new LogicException("[{$consumerClass}] does not implement ".EventConsumer::class.'.');
                }

                /** @var EventConsumer $consumer */
                $consumer = $this->app->make($consumerClass);
                $group = $consumer->group();

                if (preg_match(self::GROUP_PATTERN, $group) !== 1) {
                    throw new LogicException("Consumer group [{$group}] does not match the required shape ".self::GROUP_PATTERN.'.');
                }

                if (isset($byGroup[$group])) {
                    throw new LogicException("Consumer group [{$group}] is declared more than once.");
                }

                $byGroup[$group] = $consumer;
            }
        }

        return $byGroup;
    }

    /**
     * A real runtime check, not a trusted re-statement of the published
     * contract's own `@return list<class-string<EventConsumer>>` - a
     * provider is free to lie about that generic, which is exactly the case
     * `ConsumerRegistryTest` proves this refuses. Behind a `string`-typed
     * boundary on purpose, so the check is actually evaluated rather than
     * narrowed away by the caller's trusted docblock type.
     */
    private static function implementsEventConsumer(string $class): bool
    {
        return is_a($class, EventConsumer::class, true);
    }

    /**
     * @return list<DeclaresEventConsumers>
     */
    private function providers(): array
    {
        $providers = [];

        foreach ($this->app->getProviders(DeclaresEventConsumers::class) as $provider) {
            if ($provider instanceof DeclaresEventConsumers) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}

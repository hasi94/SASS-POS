<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Bus;

use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Exceptions\EventBusDriverNotPermitted;
use Flexipos\Kernel\Tenancy\Secrets\EnvSecretResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Which `EventBus` implementation serves this process, chosen by
 * configuration and never by module/business code (T-018 Ruling 8, AC-1) -
 * the same config-selected-contract shape
 * `Flexipos\Kernel\Tenancy\MappingSource` already uses for the tenant
 * mapping (T-017 Ruling 6 precedent).
 */
final class EventBusDriver
{
    public const REDIS = 'redis';

    public const MEMORY = 'memory';

    /**
     * @throws EventBusDriverNotPermitted
     */
    public static function resolve(Application $app): EventBus
    {
        $config = $app->make(ConfigRepository::class);
        $driver = (string) $config->get('eventbus.driver', self::REDIS);

        return match ($driver) {
            self::MEMORY => self::resolveMemory($app),
            self::REDIS => self::resolveRedis($app),
            default => throw EventBusDriverNotPermitted::unknown($driver),
        };
    }

    /**
     * @throws EventBusDriverNotPermitted
     */
    private static function resolveMemory(Application $app): EventBus
    {
        if (! $app->environment(EnvSecretResolver::LOCAL_ENVIRONMENTS)) {
            throw EventBusDriverNotPermitted::standInOutsideLocal((string) $app->environment());
        }

        /** @var EventBus */
        return $app->make(InMemoryEventBus::class);
    }

    private static function resolveRedis(Application $app): EventBus
    {
        /** @var EventBus */
        return $app->make(RedisStreamsEventBus::class);
    }
}

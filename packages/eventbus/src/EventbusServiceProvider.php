<?php

declare(strict_types=1);

namespace Flexipos\Eventbus;

use Flexipos\Contracts\Events\Outbox;
use Flexipos\Eventbus\Bus\EventBusDriver;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Bus\RedisStreamsEventBus;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Features\ConsumeEvents\ConsumeEventsCommand;
use Flexipos\Eventbus\Features\ListDeadLetters\ListDeadLettersCommand;
use Flexipos\Eventbus\Features\ListFailedOutboxEvents\ListFailedOutboxEventsCommand;
use Flexipos\Eventbus\Features\RelayOutbox\RelayOutboxCommand;
use Flexipos\Eventbus\Features\ReplayDeadLetter\ReplayDeadLetterCommand;
use Flexipos\Eventbus\Features\ReportOutboxAge\ReportOutboxAgeCommand;
use Flexipos\Eventbus\Features\RetryFailedOutboxEvent\RetryFailedOutboxEventCommand;
use Flexipos\Eventbus\Support\ConsumerRegistry;
use Flexipos\Eventbus\Support\OutboxWriter;
use Flexipos\Eventbus\Support\RelayPacing;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\ServiceProvider;

/**
 * The event bus package's entry point into the host application (T-018
 * Ruling 2, 8 and 10).
 *
 * **Binding is lazy.** `EventBus` is a singleton closure resolved through
 * {@see EventBusDriver}; booting the application never connects to
 * anything, Redis included - only the first publish, read or claim does.
 *
 * **No schedule entry.** `eventbus:relay` and `eventbus:consume` are
 * long-running workers, like `queue:work`, not scheduled commands (D-5);
 * nothing runs them in production yet (the FP-319 family).
 */
final class EventbusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InMemoryEventBus::class);
        $this->app->singleton(RelayPacing::class);
        $this->app->singleton(ConsumerRegistry::class);

        $this->app->bind(RedisStreamsEventBus::class, static function (Application $app): RedisStreamsEventBus {
            $config = $app->make(ConfigRepository::class);

            return new RedisStreamsEventBus(
                $app->make(RedisFactory::class),
                (string) $config->get('eventbus.redis.connection', 'default'),
                (int) $config->get('eventbus.max_length', 100000),
            );
        });

        $this->app->singleton(EventBus::class, static fn (Application $app): EventBus => EventBusDriver::resolve($app));

        $this->app->singleton(Outbox::class, OutboxWriter::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RelayOutboxCommand::class,
                ConsumeEventsCommand::class,
                ListFailedOutboxEventsCommand::class,
                RetryFailedOutboxEventCommand::class,
                ListDeadLettersCommand::class,
                ReplayDeadLetterCommand::class,
                ReportOutboxAgeCommand::class,
            ]);
        }
    }
}

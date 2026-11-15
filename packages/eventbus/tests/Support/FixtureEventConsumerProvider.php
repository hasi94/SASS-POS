<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Contracts\Events\DeclaresEventConsumers;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * A service provider that declares whatever consumer classes a test hands
 * it (the sibling of `Flexipos\Access\Tests\Support\FixtureAuditActionProvider`).
 *
 * Declares nothing by default and binds nothing, so registering it changes
 * the application in exactly one way: `ConsumerRegistry` sees one more
 * source.
 *
 * @phpstan-consistent-constructor the one subclass adds no constructor,
 * which is what makes `new static` in declaring() safe
 */
class FixtureEventConsumerProvider extends ServiceProvider implements DeclaresEventConsumers
{
    /** @var list<class-string> */
    protected array $consumers = [];

    /**
     * Register an instance declaring these consumer classes and return it.
     *
     * `force: true` for the reason `FixtureAuditActionProvider::declaring()`
     * gives: `register()` otherwise returns the instance already registered
     * for the class and ignores what is handed in here.
     *
     * @param  list<class-string>  $consumers
     */
    public static function declaring(Application $app, array $consumers): static
    {
        $provider = new static($app);
        $provider->consumers = $consumers;

        $app->register($provider, true);

        return $provider;
    }

    public function register(): void
    {
        //
    }

    /**
     * @return list<class-string>
     */
    public function eventConsumers(): array
    {
        return $this->consumers;
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Contracts\Events\DeclaresEventConsumers;
use Flexipos\Contracts\Events\EventConsumer;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Support\ConsumerRegistry;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 7 - the consumer registry (Ruling 7)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PingedConsumer::reset();
});

final class ConsumerRegistryDuplicateConsumer implements EventConsumer
{
    public function group(): string
    {
        return PingedConsumer::GROUP;
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return ['fixture.other'];
    }

    public function handle(EventEnvelope $event): void {}
}

final class ConsumerRegistryBadNameConsumer implements EventConsumer
{
    public function group(): string
    {
        return 'Not A Valid Name!';
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return [];
    }

    public function handle(EventEnvelope $event): void {}
}

final class ConsumerRegistryNotAConsumer {}

final class ConsumerRegistryBadDeclarationProvider extends ServiceProvider implements DeclaresEventConsumers
{
    public function register(): void {}

    /**
     * @return list<class-string>
     */
    public function eventConsumers(): array
    {
        return [ConsumerRegistryNotAConsumer::class];
    }
}

it('sees a fixture providers consumer with no memo', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $registry = app(ConsumerRegistry::class);

    expect($registry->groups())->toContain(PingedConsumer::GROUP)
        ->and($registry->resolve(PingedConsumer::GROUP))->toBeInstanceOf(PingedConsumer::class);
});

it('refuses a duplicate group name', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class, ConsumerRegistryDuplicateConsumer::class]);

    $registry = app(ConsumerRegistry::class);

    expect(fn () => $registry->groups())->toThrow(LogicException::class);
});

it('refuses a group name outside the required shape', function () {
    FixtureEventConsumerProvider::declaring(app(), [ConsumerRegistryBadNameConsumer::class]);

    $registry = app(ConsumerRegistry::class);

    expect(fn () => $registry->groups())->toThrow(LogicException::class);
});

it('refuses a class that does not implement EventConsumer', function () {
    app()->register(new ConsumerRegistryBadDeclarationProvider(app()), true);

    $registry = app(ConsumerRegistry::class);

    expect(fn () => $registry->groups())->toThrow(LogicException::class);
});

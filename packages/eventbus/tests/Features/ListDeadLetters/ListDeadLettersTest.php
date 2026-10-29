<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Features\ListDeadLetters;

use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Features\ListDeadLetters\ListDeadLetters;
use Flexipos\Eventbus\Features\ListDeadLetters\ListDeadLettersHandler;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 8 - `php artisan eventbus:dead-letters` (Ruling 10, AC-30)
|--------------------------------------------------------------------------
|
| The real-Redis read path is [ENGINE - Redis], NOT VERIFIED in this
| environment (D-1); this proves the handler's listing/formatting
| behaviour against the stand-in bus, per the spec's own carve-out.
*/

uses(TestCase::class);

it('AC-30: lists dead letters with event id, original stream, reason and delivery count', function () {
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-01 00:00:00'));

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $deadStream = (string) config('eventbus.dead_letter_stream');
    $bus->ensureGroup($stream, 'g1');

    $bus->publish($stream, eventbusContractLikeEnvelope('e1'));
    $delivered = $bus->read($stream, 'g1', 'c1', 10);
    $bus->deadLetter($deadStream, $delivered[0], 'g1', 'poison');

    $deadLetters = app(ListDeadLettersHandler::class)->handle(new ListDeadLetters);

    expect($deadLetters)->toHaveCount(1);
    expect($deadLetters[0]->eventId)->toBe('e1');
    expect($deadLetters[0]->originalStream)->toBe($stream);
    expect($deadLetters[0]->reason)->toBe('poison');
    expect($deadLetters[0]->deliveryCount)->toBe(1);
});

function eventbusContractLikeEnvelope(string $id): EventEnvelope
{
    return new EventEnvelope(
        $id,
        'tenant-a',
        'fixture.pinged',
        1,
        'fixture',
        $id,
        null,
        null,
        '2026-01-01 00:00:00.000',
        null,
        null,
        EventActor::system(),
    );
}

<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\EnforceCommercialDeadlines;

use Flexipos\Platform\Features\EnforceCommercialDeadlines\EnforceCommercialDeadlinesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 6 - the schedule registration (Ruling 2, D-14)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('schedules platform:enforce-deadlines every minute, without overlapping', function () {
    $schedule = app(Schedule::class);

    $events = array_values(array_filter(
        $schedule->events(),
        static fn ($event): bool => str_contains((string) $event->command, EnforceCommercialDeadlinesCommand::class)
            || str_contains((string) $event->command, 'platform:enforce-deadlines')
    ));

    expect($events)->not->toBeEmpty();

    $event = $events[0];

    expect($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

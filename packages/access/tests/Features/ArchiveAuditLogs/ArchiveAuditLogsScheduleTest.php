<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\ArchiveAuditLogs;

use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 8 - the schedule registration (Ruling 6, AC-8, D-14)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('schedules audit:archive daily at 03:00, without overlapping', function () {
    $schedule = app(Schedule::class);

    $events = array_values(array_filter(
        $schedule->events(),
        static fn ($event): bool => str_contains((string) $event->command, ArchiveAuditLogsCommand::class)
            || str_contains((string) $event->command, 'audit:archive')
    ));

    expect($events)->not->toBeEmpty();

    $event = $events[0];

    expect($event->expression)->toBe('0 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

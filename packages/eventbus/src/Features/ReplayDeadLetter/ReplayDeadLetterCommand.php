<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ReplayDeadLetter;

use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan eventbus:replay {dead-id}` (T-018 Ruling 10, AC-31).
 */
final class ReplayDeadLetterCommand extends Command
{
    protected $signature = 'eventbus:replay {dead-id}';

    protected $description = 'Re-publish a dead-lettered event onto its original stream';

    public function handle(ReplayDeadLetterHandler $handler): int
    {
        $deadId = (string) $this->argument('dead-id');

        try {
            $newId = $handler->handle(new ReplayDeadLetter($deadId));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Dead-letter [{$deadId}] replayed as message [{$newId}].");

        return self::SUCCESS;
    }
}

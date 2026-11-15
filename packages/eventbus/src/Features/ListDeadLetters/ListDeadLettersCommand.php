<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ListDeadLetters;

use Illuminate\Console\Command;

/**
 * `php artisan eventbus:dead-letters` (T-018 Ruling 10, AC-30).
 */
final class ListDeadLettersCommand extends Command
{
    protected $signature = 'eventbus:dead-letters {--newest} {--limit=}';

    protected $description = "List the dead-letter stream's current entries";

    public function handle(ListDeadLettersHandler $handler): int
    {
        $limitOption = $this->option('limit');

        $deadLetters = $handler->handle(new ListDeadLetters(
            newestFirst: (bool) $this->option('newest'),
            limit: $limitOption !== null ? (int) $limitOption : null,
        ));

        if ($deadLetters === []) {
            $this->components->info('No dead-lettered events.');

            return self::SUCCESS;
        }

        $rows = array_map(static fn ($deadLetter): array => [
            $deadLetter->deadId,
            $deadLetter->eventId,
            $deadLetter->tenantId,
            $deadLetter->type,
            $deadLetter->originalStream,
            $deadLetter->group,
            $deadLetter->deliveryCount,
            $deadLetter->reason,
            $deadLetter->deadAt,
        ], $deadLetters);

        $this->table(
            ['dead_id', 'event_id', 'tenant_id', 'type', 'original_stream', 'group', 'delivery_count', 'reason', 'dead_at'],
            $rows
        );

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Illuminate\Database\Events\QueryExecuted;

/**
 * Every statement an operation sends, in the order it ran, so a test can record
 * a query count (AC-94) or compare one operation's reads with another's.
 *
 * Reads the fixture connection only. The lock-marking grammar and transaction
 * boundary markers the write slices need (Ruling 7) arrive with those slices.
 */
final class PurchasingQueryLog
{
    /**
     * @param  callable(): mixed  $operation
     * @return list<string> the SQL of each statement
     */
    public static function during(callable $operation): array
    {
        $recorder = new class
        {
            public bool $recording = true;

            /** @var list<string> */
            public array $log = [];
        };

        // A listener cannot be removed from a connection, so it is switched off.
        PurchasingSeed::connection()->listen(static function (QueryExecuted $query) use ($recorder): void {
            if ($recorder->recording) {
                $recorder->log[] = $query->sql;
            }
        });

        try {
            $operation();
        } finally {
            $recorder->recording = false;
        }

        return $recorder->log;
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Every statement an operation sends, with the locking reads visible
 * ({@see LockMarkingGrammar}) and the transaction boundaries marked, so a test
 * can pin Ruling 7: that the first statement of a write transaction is a locking
 * read, and in which order the rows are locked.
 */
final class SalesQueryLog
{
    public const BEGIN = '-- begin';

    public const END = '-- end';

    /**
     * @param  callable(): mixed  $operation
     * @return list<string> the SQL of each statement in the order it ran, with {@see self::BEGIN} and {@see self::END} where a transaction opened and closed
     */
    public static function during(callable $operation): array
    {
        $connection = SalesSeed::connection();
        $standIn = $connection->getQueryGrammar();
        $log = [];

        $connection->setQueryGrammar(new LockMarkingGrammar($connection));
        $connection->listen(static function (QueryExecuted $query) use (&$log): void {
            $log[] = $query->sql;
        });

        $events = app('events');
        $events->listen(TransactionBeginning::class, static function () use (&$log): void {
            $log[] = self::BEGIN;
        });
        $events->listen([TransactionCommitted::class, TransactionRolledBack::class], static function () use (&$log): void {
            $log[] = self::END;
        });

        try {
            $operation();
        } finally {
            $connection->setQueryGrammar($standIn);
        }

        return $log;
    }

    /**
     * What ran inside the first transaction.
     *
     * @param  list<string>  $log
     * @return list<string>
     */
    public static function inTransaction(array $log): array
    {
        $begin = array_search(self::BEGIN, $log, true);

        if ($begin === false) {
            return [];
        }

        $inside = [];

        foreach (array_slice($log, $begin + 1) as $entry) {
            if ($entry === self::END) {
                break;
            }

            $inside[] = $entry;
        }

        return $inside;
    }
}

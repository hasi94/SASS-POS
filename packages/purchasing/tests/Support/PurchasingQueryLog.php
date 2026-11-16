<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Flexipos\Pricing\Tests\Support\LockMarkingGrammar;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Every statement an operation sends, in the order it ran, so a test can record
 * a query count (AC-94) or compare one operation's reads with another's.
 *
 * {@see self::duringLocked()} also makes the locking reads visible (pricing's
 * `LockMarkingGrammar`) and marks the transaction boundaries, so a test can pin
 * Ruling 7: that the first statement of a write transaction is a locking read, and
 * in which order the rows are locked. That pins the clause that was sent, never
 * that the engine blocks.
 */
final class PurchasingQueryLog
{
    public const BEGIN = '-- begin';

    public const END = '-- end';

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

    /**
     * Like {@see self::during()}, with the lock clauses written into the log and
     * {@see self::BEGIN} and {@see self::END} where a transaction opened and closed.
     * A refusal is not caught: the caller wraps the operation if it expects one.
     *
     * @param  callable(): mixed  $operation
     * @return list<string>
     */
    public static function duringLocked(callable $operation): array
    {
        $connection = PurchasingSeed::connection();
        $standIn = $connection->getQueryGrammar();

        $recorder = new class
        {
            public bool $recording = true;

            /** @var list<string> */
            public array $log = [];
        };

        $connection->setQueryGrammar(new LockMarkingGrammar($connection));
        $connection->listen(static function (QueryExecuted $query) use ($recorder): void {
            if ($recorder->recording) {
                $recorder->log[] = $query->sql;
            }
        });

        $events = app('events');
        $events->listen(TransactionBeginning::class, static function () use ($recorder): void {
            if ($recorder->recording) {
                $recorder->log[] = self::BEGIN;
            }
        });
        $events->listen([TransactionCommitted::class, TransactionRolledBack::class], static function () use ($recorder): void {
            if ($recorder->recording) {
                $recorder->log[] = self::END;
            }
        });

        try {
            $operation();
        } finally {
            $recorder->recording = false;
            $connection->setQueryGrammar($standIn);
        }

        return $recorder->log;
    }

    /**
     * What ran inside the first transaction, nested ones included.
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

        // A nested transaction (a savepoint) raises and lowers the depth: only the first
        // transaction's own close ends the slice, and the nested markers are not part of
        // what ran.
        $inside = [];
        $depth = 1;

        foreach (array_slice($log, $begin + 1) as $entry) {
            if ($entry === self::BEGIN) {
                $depth++;

                continue;
            }

            if ($entry === self::END) {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            $inside[] = $entry;
        }

        return $inside;
    }
}

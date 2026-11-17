<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Support;

use Closure;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * A `DocumentNumbers` that does something first on every call, and the engine's
 * deadlock error to throw from it (T-030 step 17).
 *
 * Moved out of `OpenCashShiftTest`, where these were file-level functions: Pest
 * declares a test file's functions globally, so a second test file that needed
 * them (`CompleteSale`, `RefundSale`) would be a fatal redeclaration in one run.
 * Every use is **NOT VERIFIED on a real engine**: it proves the retry's shape
 * (the whole transaction rolls back and runs once more, a second deadlock
 * surfaces, anything else is not retried), never that the engine deadlocks there.
 */
final class DeadlockingNumbers
{
    /**
     * Replace `DocumentNumbers` with a wrapper around the real one that runs `$before` on every call first.
     *
     * @param  Closure(int): void  $before  given the 1-based call number
     * @return object{calls: int}
     */
    public static function wrap(Closure $before): object
    {
        $real = app(DocumentNumbers::class);

        $wrapper = new class($real, $before) implements DocumentNumbers
        {
            public int $calls = 0;

            public function __construct(private readonly DocumentNumbers $real, private readonly Closure $before) {}

            public function next(string $documentType, ?string $branchId): string
            {
                $this->calls++;
                ($this->before)($this->calls);

                return $this->real->next($documentType, $branchId);
            }
        };

        app()->instance(DocumentNumbers::class, $wrapper);

        return $wrapper;
    }

    /**
     * The error the engine raises for a deadlock victim (SQLSTATE 40001, error 1213).
     */
    public static function deadlock(): QueryException
    {
        return new QueryException(
            'tenant',
            'select * from "number_sequences" where "branch_id_key" = ? for update',
            [],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')
        );
    }
}

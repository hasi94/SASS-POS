<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Support;

use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use PHPUnit\Framework\Assert;

/**
 * Run an operation that must be refused, and hand back the refusal (the
 * `CustomersRefusals` shape).
 *
 * **It fails when nothing is thrown**, which a bare `try`/`catch` keeps
 * forgetting: a catch block alone passes silently when the operation succeeds,
 * the exact outcome a guard test exists to detect.
 */
final class SalesRefusals
{
    /**
     * @param  callable(): mixed  $operation
     */
    public static function capture(callable $operation): SalesRefused
    {
        try {
            $operation();
        } catch (SalesRefused $refused) {
            return $refused;
        }

        Assert::fail('The operation was expected to be refused and succeeded.');
    }

    /**
     * @param  callable(): mixed  $operation
     */
    public static function expect(SalesRefusal $reason, callable $operation, ?string $context = null): SalesRefused
    {
        $refused = self::capture($operation);

        $where = $context === null ? '' : " [{$context}]";

        Assert::assertSame(
            $reason,
            $refused->reason,
            "Expected [{$reason->value}] and got [{$refused->reason->value}]{$where}: {$refused->getMessage()}"
        );

        return $refused;
    }
}

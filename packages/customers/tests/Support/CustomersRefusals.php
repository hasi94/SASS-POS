<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Support;

use Flexipos\Customers\Exceptions\CustomersRefusal;
use Flexipos\Customers\Exceptions\CustomersRefused;
use PHPUnit\Framework\Assert;

/**
 * Run an operation that must be refused, and hand back the refusal (the
 * `CatalogRefusals`/`AccessRefusals` shape, T-028 plan section 7).
 *
 * **It fails when nothing is thrown**, which a bare `try`/`catch` keeps
 * forgetting: a catch block alone passes silently when the operation
 * succeeds, the exact outcome a guard test exists to detect.
 */
final class CustomersRefusals
{
    /**
     * @param  callable(): mixed  $operation
     */
    public static function capture(callable $operation): CustomersRefused
    {
        try {
            $operation();
        } catch (CustomersRefused $refused) {
            return $refused;
        }

        Assert::fail('The operation was expected to be refused and succeeded.');
    }

    /**
     * @param  callable(): mixed  $operation
     */
    public static function expect(CustomersRefusal $reason, callable $operation, ?string $context = null): CustomersRefused
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

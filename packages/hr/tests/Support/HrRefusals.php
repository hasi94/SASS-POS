<?php

declare(strict_types=1);

namespace Flexipos\Hr\Tests\Support;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;
use PHPUnit\Framework\Assert;

/**
 * Run an operation that must be refused, and hand back the refusal.
 *
 * **It fails when nothing is thrown**, which a bare `try`/`catch` keeps
 * forgetting: a catch block alone passes silently when the operation
 * succeeds, the exact outcome a guard test exists to detect.
 */
final class HrRefusals
{
    /**
     * @param  callable(): mixed  $operation
     */
    public static function capture(callable $operation): HrRefused
    {
        try {
            $operation();
        } catch (HrRefused $refused) {
            return $refused;
        }

        Assert::fail('The operation was expected to be refused and succeeded.');
    }

    /**
     * @param  callable(): mixed  $operation
     */
    public static function expect(HrRefusal $reason, callable $operation, ?string $context = null): HrRefused
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

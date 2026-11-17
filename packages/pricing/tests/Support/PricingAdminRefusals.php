<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Support;

use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Support\PricingAdminRefusal;
use PHPUnit\Framework\Assert;

/**
 * Run an operation that must be refused, and hand back the refusal (the
 * `CatalogRefusals` shape).
 *
 * **It fails when nothing is thrown**, which a bare `try`/`catch` keeps
 * forgetting: a catch block alone passes silently when the operation succeeds,
 * the exact outcome a guard test exists to detect. This is for the CRUD slices'
 * own refusals; the engine's `PricingRefused` is read with `Build::reasonOf`.
 */
final class PricingAdminRefusals
{
    /**
     * @param  callable(): mixed  $operation
     */
    public static function capture(callable $operation): PricingAdminRefused
    {
        try {
            $operation();
        } catch (PricingAdminRefused $refused) {
            return $refused;
        }

        Assert::fail('The operation was expected to be refused and succeeded.');
    }

    /**
     * @param  callable(): mixed  $operation
     */
    public static function expect(PricingAdminRefusal $reason, callable $operation, ?string $context = null): PricingAdminRefused
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

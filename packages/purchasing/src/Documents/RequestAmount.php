<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Money;

/**
 * The amount a purchase request is approved on (T-034 AC-26, Ruling 6): the sum
 * over its lines of `quantity x estimated_cost`, each product rounded half-up at
 * four decimals, a line with no estimate adding zero.
 *
 * **The one caller of {@see Money::product()}** (the source-rules scan pins that):
 * a request carries no tax, discount or factor arithmetic, so the only product in
 * the request path is this one, and it is a quantity times a stored cost, never a
 * rate or a percent. An amount that cannot fit `DECIMAL(18,4)` is `out_of_range`
 * and is refused before the workflow engine is asked anything.
 */
final class RequestAmount
{
    /**
     * @param  iterable<array<string, mixed>>  $lines  rows carrying `quantity` and `estimated_cost` (null for none)
     *
     * @throws PurchasingRefused out_of_range
     */
    public static function of(iterable $lines): string
    {
        $parts = [];

        foreach ($lines as $line) {
            $cost = $line['estimated_cost'] ?? null;

            if ($cost === null) {
                continue;
            }

            $parts[] = Money::product(Money::of($line['quantity']), Money::of($cost));
        }

        $amount = Money::sum($parts);

        if (! Money::fits($amount)) {
            throw PurchasingRefused::outOfRange('The request amount would not fit DECIMAL(18,4).');
        }

        return $amount;
    }
}

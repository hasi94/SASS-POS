<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/**
 * Which currency an order is written in (T-034 AC-40, D-41, Q-B4).
 *
 * The chain: the **explicit** currency the caller named, else the **supplier's**,
 * else the **branch's**. `currency_required` is reached only when all three are
 * absent or malformed, which a real branch (`CHAR(3) NOT NULL`) makes a defensive
 * refusal: a blank or non-alphabetic branch currency is treated as none. The
 * currency is data; no conversion happens (D-20). Pure.
 */
final class OrderCurrency
{
    /**
     * A submitted currency: three letters, folded to upper case.
     *
     * @throws PurchasingRefused validation
     */
    public static function explicit(mixed $submitted): string
    {
        if (! is_string($submitted) || preg_match('/^[A-Za-z]{3}$/D', trim($submitted)) !== 1) {
            throw PurchasingRefused::validation('The currency must be three letters.');
        }

        return strtoupper(trim($submitted));
    }

    /**
     * @param  ?string  $explicit  already folded by {@see self::explicit()}, or null when the caller named none
     * @param  ?string  $supplier  the supplier's stored currency
     * @param  string  $branch  the branch's stored currency
     *
     * @throws PurchasingRefused currency_required
     */
    public static function resolve(?string $explicit, ?string $supplier, string $branch): string
    {
        foreach ([$explicit, $supplier, $branch] as $candidate) {
            $candidate = $candidate === null ? '' : strtoupper(trim($candidate));

            if (preg_match('/^[A-Z]{3}$/D', $candidate) === 1) {
                return $candidate;
            }
        }

        throw PurchasingRefused::because(
            PurchasingRefusal::CurrencyRequired,
            'No currency was named, the supplier has none and the branch has none.',
        );
    }
}

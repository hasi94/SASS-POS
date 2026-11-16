<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Suppliers;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/**
 * What a supplier code is (T-034 AC-2, AC-11, D-5).
 *
 * One to thirty characters, a letter or a digit first, then letters, digits,
 * `.`, `_` or `-`; folded to upper case. The column is wider (50) than the rule
 * (30) on purpose (D-32): tightening a rule is a code change, widening a column
 * is a migration. The code never changes after the supplier is created.
 */
final class SupplierCode
{
    public const MAX = 30;

    /**
     * @throws PurchasingRefused validation
     */
    public static function normalise(mixed $submitted): string
    {
        if (! is_string($submitted)) {
            throw PurchasingRefused::validation('The code is required and must be a string.');
        }

        $code = trim($submitted);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,'.(self::MAX - 1).'}$/D', $code) !== 1) {
            throw PurchasingRefused::validation(
                'The code must be 1 to '.self::MAX.' characters: letters, digits, dot, underscore or hyphen, starting with a letter or a digit.'
            );
        }

        return strtoupper($code);
    }
}

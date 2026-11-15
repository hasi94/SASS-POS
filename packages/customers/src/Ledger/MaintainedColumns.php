<?php

declare(strict_types=1);

namespace Flexipos\Customers\Ledger;

use Flexipos\Customers\Exceptions\CustomersRefused;

/**
 * The columns an ordinary create/update may never set (T-028 plan Ruling 7,
 * AC-8, D-8, D-29, F-11, F-12): `credit_balance`, `loyalty_points` and
 * `loyalty_tier` are maintained only by the two ledger writers;
 * `merged_into_id` only by `MergeCustomers` (D-29); `opening_balance` only
 * at creation, never by an update (F-11).
 *
 * Lives under `Ledger/` by design (plan section 3): the one-writer source
 * scan (step 16) names this file as the sole exemption for the maintained
 * column tokens outside `Ledger/`/`Loyalty/` themselves.
 */
final class MaintainedColumns
{
    /** @var list<string> */
    public const CREATE = ['credit_balance', 'loyalty_points', 'loyalty_tier', 'merged_into_id'];

    /** @var list<string> */
    public const UPDATE = ['credit_balance', 'loyalty_points', 'loyalty_tier', 'merged_into_id', 'opening_balance'];

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $maintained
     *
     * @throws CustomersRefused maintained
     */
    public static function refuse(array $fields, array $maintained): void
    {
        $found = array_values(array_intersect(array_map('strval', array_keys($fields)), $maintained));

        if ($found !== []) {
            throw CustomersRefused::maintained('These fields are maintained only by the ledger and cannot be set directly: '.implode(', ', $found).'.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Customers\Support;

use Flexipos\Contracts\Finance\AccountKind;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Customers\Exceptions\CustomersRefused;

/**
 * The account column a customer may name (T-032 AC-55, AC-56).
 *
 * **Customers never reads `accounts`.** Whether an account exists, is live,
 * active, postable and a receivable is owned by Finance and asked through the
 * published {@see ChartOfAccounts::assignable()}, which takes a shared lock on
 * the account and so must run inside the write's own transaction (AC-57).
 *
 * Two steps, so a malformed id is refused before any lock is taken:
 *
 * - {@see self::fold()} reads the field out of the request (the id folded to
 *   upper case, or null to clear), and refuses a malformed id with this
 *   package's own validation naming the field;
 * - {@see self::assertAssignable()}, inside the transaction, asks Finance only
 *   when the value **changes to a non-null id**. Null clears with no read, an
 *   omitted field is not in the map at all, and a value equal to the stored
 *   one is left alone.
 */
final class AccountFields
{
    public const FIELD = 'receivable_account_id';

    /**
     * The field when the request carries it, folded.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, ?string>
     *
     * @throws CustomersRefused validation
     */
    public static function fold(array $fields): array
    {
        if (! Fields::has($fields, self::FIELD)) {
            return [];
        }

        return [self::FIELD => Identifier::foldNullable($fields[self::FIELD], self::FIELD)];
    }

    /**
     * @param  array<string, ?string>  $values
     * @param  object|null  $current  the stored row, or null on a create
     *
     * @throws AccountRefused
     */
    public static function assertAssignable(ChartOfAccounts $chart, array $values, ?object $current = null): void
    {
        $id = $values[self::FIELD] ?? null;

        if ($id === null || ($current !== null && ($current->{self::FIELD} ?? null) === $id)) {
            return;
        }

        $chart->assignable(self::FIELD, $id, AccountKind::Receivable);
    }
}

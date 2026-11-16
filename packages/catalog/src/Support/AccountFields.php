<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Support;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Contracts\Finance\AccountKind;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Contracts\Finance\ChartOfAccounts;

/**
 * The account columns a product or a category may name (T-032 AC-55, AC-56).
 *
 * **Catalog never reads `accounts`.** Whether an account exists, is live,
 * active, postable and of the right kind is owned by Finance and asked through
 * the published {@see ChartOfAccounts::assignable()}, which takes a shared lock
 * on the account and so must run inside the write's own transaction (AC-57).
 *
 * Two steps, so a malformed id is refused before any lock is taken:
 *
 * - {@see self::fold()} reads the named fields out of the request (the id
 *   folded to upper case, or null to clear), and refuses a malformed id with
 *   this package's own validation naming the field;
 * - {@see self::assertAssignable()}, inside the transaction, asks Finance
 *   about each value **that changes to a non-null id**. Null clears with no
 *   read, an omitted field is not in the map at all, and a value equal to the
 *   stored one is left alone (so an account deactivated since cannot make an
 *   unrelated edit of the row fail).
 */
final class AccountFields
{
    public const PRODUCT = ['income_account_id', 'cogs_account_id', 'inventory_account_id'];

    public const CATEGORY = ['income_account_id'];

    /**
     * The kind each field requires (AC-56).
     */
    private const KINDS = [
        'income_account_id' => AccountKind::Income,
        'cogs_account_id' => AccountKind::Cogs,
        'inventory_account_id' => AccountKind::Inventory,
    ];

    /**
     * The named fields that the request carries, folded.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $names
     * @return array<string, ?string>
     *
     * @throws CatalogRefused validation
     */
    public static function fold(array $fields, array $names): array
    {
        $folded = [];

        foreach ($names as $name) {
            if (Fields::has($fields, $name)) {
                $folded[$name] = Identifier::foldNullable($fields[$name], $name);
            }
        }

        return $folded;
    }

    /**
     * Ask Finance about every value that changes to a non-null id.
     *
     * @param  array<string, ?string>  $values
     * @param  object|null  $current  the stored row, or null on a create
     *
     * @throws AccountRefused
     */
    public static function assertAssignable(ChartOfAccounts $chart, array $values, ?object $current = null): void
    {
        foreach ($values as $field => $id) {
            if ($id === null) {
                continue;
            }

            if ($current !== null && ($current->{$field} ?? null) === $id) {
                continue;
            }

            $chart->assignable($field, $id, self::KINDS[$field]);
        }
    }
}

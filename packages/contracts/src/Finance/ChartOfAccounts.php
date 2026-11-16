<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

use LogicException;

/**
 * The published read side of the chart of accounts (T-032 AC-55, AC-63).
 *
 * Other modules reach accounts only through this interface and the immutable
 * {@see AccountSnapshot} it returns; no model class crosses the boundary.
 */
interface ChartOfAccounts
{
    /**
     * @throws AccountRefused `account_not_found`
     */
    public function byId(string $id): AccountSnapshot;

    /**
     * The code is matched after the same upper-casing the API applies.
     *
     * @throws AccountRefused `invalid_code` or `account_not_found`
     */
    public function byCode(string $code): AccountSnapshot;

    /**
     * Live accounts holding this control type, ordered by code.
     *
     * @return list<AccountSnapshot>
     */
    public function byControlType(ControlType $type): array;

    /**
     * An account a posting may name: live, active and postable. Takes a
     * shared lock when a transaction is open.
     *
     * @throws AccountRefused `account_not_found`, `account_not_live`, `account_inactive` or `account_not_postable`
     */
    public function postable(string $id): AccountSnapshot;

    /**
     * Validate an account assigned to a product, category or customer
     * (AC-55, AC-56). `$field` is the request field, carried into the refusal.
     *
     * **Must be called inside the caller's transaction**: it takes a shared
     * lock on the account so a concurrent delete or deactivation cannot leave
     * a live referrer pointing at a dead account (AC-57).
     *
     * @throws AccountRefused `account_not_found`, `account_not_live`, `account_inactive`, `account_not_postable` or `account_kind_mismatch`
     * @throws LogicException when no transaction is open
     */
    public function assignable(string $field, string $id, AccountKind $kind): AccountSnapshot;
}

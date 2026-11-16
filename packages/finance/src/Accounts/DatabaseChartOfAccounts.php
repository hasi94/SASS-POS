<?php

declare(strict_types=1);

namespace Flexipos\Finance\Accounts;

use Flexipos\Contracts\Finance\AccountKind;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Contracts\Finance\AccountSnapshot;
use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Contracts\Finance\NormalBalance;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use LogicException;

/**
 * The published read contract, answered from the tenant database (T-032 plan
 * Ruling 2, AC-55 to AC-57, AC-63).
 *
 * **Not a slice and needs no permission** (Ruling 9): it is called by trusted
 * module code, never by a request, so it asks nobody who the actor is. The
 * callers decide who may do what; this class only answers what an account is
 * and whether it may be used.
 *
 * Every answer is an immutable {@see AccountSnapshot}, never a row, so no
 * other module sees (or comes to depend on) this package's table shape
 * (AC-51's spirit for accounts).
 *
 * **Locks.** `postable()` takes a shared lock when a transaction is open, so
 * an account cannot be deleted or deactivated between the check and the
 * posting that relies on it. `assignable()` **must** run inside the caller's
 * own transaction and always takes the shared lock (AC-57): a concurrent
 * delete or deactivation then waits for the write that is about to name the
 * account, and the referrer count that delete takes (under the same lock
 * discipline) cannot miss it.
 */
final class DatabaseChartOfAccounts implements ChartOfAccounts
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AccountRows $rows,
    ) {}

    public function byId(string $id): AccountSnapshot
    {
        $row = $this->rows->byIdUnlocked($this->foldId($id));

        return $this->live($row, $id, null);
    }

    public function byCode(string $code): AccountSnapshot
    {
        try {
            $folded = Input::code($code);
        } catch (FinanceRefused $refused) {
            throw AccountRefused::invalidCode($refused->getMessage(), 'code');
        }

        // A soft-deleted account keeps its code reserved, so the read must see
        // it to say `account_not_live` rather than "no such account".
        $row = $this->rows->byCodeUnlocked($folded);

        return $this->live($row, $code, null);
    }

    public function byControlType(ControlType $type): array
    {
        return array_map(fn (object $row): AccountSnapshot => $this->snapshot($row), $this->rows->liveByControlTypeUnlocked($type));
    }

    public function postable(string $id): AccountSnapshot
    {
        $folded = $this->foldId($id);
        $row = $this->tenant->get()->transactionLevel() > 0
            ? $this->rows->anySharedLock($folded)
            : $this->rows->byIdUnlocked($folded);

        $snapshot = $this->live($row, $id, null);

        if (! $snapshot->isActive) {
            throw AccountRefused::inactive("The account [{$snapshot->id}] is inactive.");
        }

        if (! $snapshot->isPostable) {
            throw AccountRefused::notPostable("The account [{$snapshot->id}] is a header and takes no postings.");
        }

        return $snapshot;
    }

    public function assignable(string $field, string $id, AccountKind $kind): AccountSnapshot
    {
        if ($this->tenant->get()->transactionLevel() < 1) {
            throw new LogicException('ChartOfAccounts::assignable() must be called inside the caller\'s transaction (AC-57).');
        }

        $row = $this->rows->anySharedLock($this->foldId($id));

        $snapshot = $this->live($row, $id, $field);

        if (! $snapshot->isActive) {
            throw AccountRefused::inactive("The account [{$snapshot->id}] is inactive.", $field);
        }

        if (! $snapshot->isPostable) {
            throw AccountRefused::notPostable("The account [{$snapshot->id}] is a header and takes no postings.", $field);
        }

        if (! $kind->accepts($snapshot)) {
            throw AccountRefused::kindMismatch("The account [{$snapshot->id}] is not a {$kind->value} account.", $field);
        }

        return $snapshot;
    }

    /**
     * A well-formed id folded to upper case, or the empty string, which no
     * row has: a malformed id is "not found" to a contract caller, never an
     * exception of another type.
     */
    private function foldId(string $id): string
    {
        return preg_match('/\A[0-9A-Za-z]{26}\z/', $id) === 1 ? strtoupper($id) : '';
    }

    private function live(?object $row, string $asked, ?string $field): AccountSnapshot
    {
        if ($row === null) {
            throw AccountRefused::notFound('There is no account ['.$asked.'].', $field);
        }

        if ($row->deleted_at !== null) {
            throw AccountRefused::notLive("The account [{$row->id}] has been deleted.", $field);
        }

        return $this->snapshot($row);
    }

    private function snapshot(object $row): AccountSnapshot
    {
        /** @var object{id: string, code: string, name: string, type: string, subtype: ?string, normal_balance: string, is_postable: int|bool, is_active: int|bool, control_type: string} $row */
        return new AccountSnapshot(
            (string) $row->id,
            (string) $row->code,
            (string) $row->name,
            AccountType::from((string) $row->type),
            $row->subtype === null ? null : AccountSubtype::from((string) $row->subtype),
            NormalBalance::from((string) $row->normal_balance),
            (bool) $row->is_postable,
            (bool) $row->is_active,
            ControlType::from((string) $row->control_type),
        );
    }
}

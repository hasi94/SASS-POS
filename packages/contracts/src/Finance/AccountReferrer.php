<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

use InvalidArgumentException;

/**
 * How many records of one kind point at an account (T-032 AC-23, AC-24,
 * AC-25).
 *
 * `kind` names the referrer, for example `products.income_account_id`.
 * `live` and `deleted` are separate because deactivating an account counts
 * only live referrers while deleting it counts both (D-14).
 */
final readonly class AccountReferrer
{
    public function __construct(
        public string $kind,
        public int $live,
        public int $deleted,
    ) {
        if ($kind === '') {
            throw new InvalidArgumentException('[kind] must not be empty.');
        }

        if ($live < 0) {
            throw new InvalidArgumentException('[live] must not be negative.');
        }

        if ($deleted < 0) {
            throw new InvalidArgumentException('[deleted] must not be negative.');
        }
    }

    /**
     * Every referrer, live or soft-deleted.
     */
    public function total(): int
    {
        return $this->live + $this->deleted;
    }
}

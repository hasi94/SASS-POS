<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

use InvalidArgumentException;

/**
 * A request to record one `customer_ledger` row (T-028 plan Ruling 2).
 *
 * **Shape only**, the `ResolvedFactor`/`UnitSnapshot` discipline: this
 * constructor checks that the ids are ULIDs, that `referenceType` is 1 to
 * 50 characters, and that a supplied `currency` is three letters, folded to
 * upper case. It never checks `debit`/`credit` - that is
 * `CreditLedgerCalculator`'s job (Ruling 3, Ruling 4), because a float, an
 * out-of-range amount or a wrong side are all calculator-level refusals,
 * never a construction-time one.
 */
final readonly class CreditLedgerEntry
{
    private const ULID_LENGTH = 26;

    public string $customerId;

    public string $referenceType;

    public string $referenceId;

    public ?string $currency;

    public ?string $branchId;

    public ?string $employeeId;

    public ?string $actorId;

    public function __construct(
        string $customerId,
        public CreditEntryType $type,
        string $referenceType,
        string $referenceId,
        public int|string $debit,
        public int|string $credit,
        ?string $currency = null,
        ?string $branchId = null,
        ?string $employeeId = null,
        public ?string $notes = null,
        ?string $actorId = null,
    ) {
        $this->customerId = self::foldUlid($customerId, 'customerId');

        if ($referenceType === '' || mb_strlen($referenceType) > 50) {
            throw new InvalidArgumentException('[referenceType] must be 1 to 50 characters.');
        }

        $this->referenceType = $referenceType;
        $this->referenceId = self::foldUlid($referenceId, 'referenceId');
        $this->currency = $currency === null ? null : self::foldCurrency($currency);
        $this->branchId = $branchId === null ? null : self::foldUlid($branchId, 'branchId');
        $this->employeeId = $employeeId === null ? null : self::foldUlid($employeeId, 'employeeId');
        $this->actorId = $actorId === null ? null : self::foldUlid($actorId, 'actorId');
    }

    private static function foldUlid(string $value, string $what): string
    {
        if (mb_strlen($value) !== self::ULID_LENGTH || preg_match('/^[0-9A-Za-z]{26}$/', $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] is not a {".self::ULID_LENGTH.'}-character ULID.');
        }

        return strtoupper($value);
    }

    private static function foldCurrency(string $currency): string
    {
        if (preg_match('/\A[A-Za-z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException('[currency] must be three letters.');
        }

        return strtoupper($currency);
    }
}

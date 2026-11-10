<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

use InvalidArgumentException;

/**
 * What `CustomerCreditLedger::record()` returns: the written row's canonical
 * shape (T-028 plan Ruling 2, Ruling 3).
 *
 * **Shape only.** This constructor never recomputes `balanceAfter` - it
 * checks that the ids are ULIDs, that `debit`/`credit`/`balanceAfter` are
 * canonical `DECIMAL(18,4)` strings (`balanceAfter` may be negative;
 * `debit`/`credit` may not), and that `currency` is three letters.
 */
final readonly class CreditLedgerPosting
{
    private const ULID_LENGTH = 26;

    /** `DECIMAL(18,4)`, never negative: up to 14 integer digits, exactly 4 fraction digits. */
    private const UNSIGNED_AMOUNT_PATTERN = '/^\d{1,14}\.\d{4}$/';

    /** `DECIMAL(18,4)`, optionally negative: up to 14 integer digits, exactly 4 fraction digits. */
    private const SIGNED_AMOUNT_PATTERN = '/^-?\d{1,14}\.\d{4}$/';

    public string $entryId;

    public string $customerId;

    public string $debit;

    public string $credit;

    public string $balanceAfter;

    public string $currency;

    public function __construct(
        string $entryId,
        string $customerId,
        public CreditEntryType $type,
        string $debit,
        string $credit,
        string $balanceAfter,
        string $currency,
        public bool $replayed = false,
    ) {
        $this->entryId = self::foldUlid($entryId, 'entryId');
        $this->customerId = self::foldUlid($customerId, 'customerId');
        $this->debit = self::checkAmount($debit, self::UNSIGNED_AMOUNT_PATTERN, 'debit');
        $this->credit = self::checkAmount($credit, self::UNSIGNED_AMOUNT_PATTERN, 'credit');
        $this->balanceAfter = self::checkAmount($balanceAfter, self::SIGNED_AMOUNT_PATTERN, 'balanceAfter');
        $this->currency = self::foldCurrency($currency);
    }

    private static function foldUlid(string $value, string $what): string
    {
        if (mb_strlen($value) !== self::ULID_LENGTH || preg_match('/^[0-9A-Za-z]{26}$/', $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] is not a {".self::ULID_LENGTH.'}-character ULID.');
        }

        return strtoupper($value);
    }

    private static function checkAmount(string $value, string $pattern, string $what): string
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] must be a DECIMAL(18,4) string.");
        }

        return $value;
    }

    private static function foldCurrency(string $currency): string
    {
        if (preg_match('/\A[A-Za-z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException('[currency] must be three letters.');
        }

        return strtoupper($currency);
    }
}

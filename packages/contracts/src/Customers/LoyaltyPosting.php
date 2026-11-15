<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

use InvalidArgumentException;

/**
 * What `LoyaltyLedger::record()` returns: the written row's canonical shape
 * (T-028 plan Ruling 2, Ruling 5).
 *
 * **Shape only.** This constructor never recomputes `balanceAfter` or
 * `tier` - it checks that the ids are ULIDs, that `points` and
 * `balanceAfter` are canonical `DECIMAL(18,4)` strings, and that a supplied
 * `tier` is at most 50 characters.
 */
final readonly class LoyaltyPosting
{
    private const ULID_LENGTH = 26;

    /** `DECIMAL(18,4)`, optionally negative: up to 14 integer digits, exactly 4 fraction digits. */
    private const SIGNED_AMOUNT_PATTERN = '/^-?\d{1,14}\.\d{4}$/';

    public string $transactionId;

    public string $customerId;

    public string $programId;

    public string $points;

    public string $balanceAfter;

    public ?string $tier;

    public function __construct(
        string $transactionId,
        string $customerId,
        string $programId,
        public LoyaltyTransactionType $type,
        string $points,
        string $balanceAfter,
        ?string $tier = null,
        public bool $replayed = false,
    ) {
        $this->transactionId = self::foldUlid($transactionId, 'transactionId');
        $this->customerId = self::foldUlid($customerId, 'customerId');
        $this->programId = self::foldUlid($programId, 'programId');
        $this->points = self::checkAmount($points, 'points');
        $this->balanceAfter = self::checkAmount($balanceAfter, 'balanceAfter');

        if ($tier !== null && ($tier === '' || mb_strlen($tier) > 50)) {
            throw new InvalidArgumentException('[tier] must be 1 to 50 characters.');
        }

        $this->tier = $tier;
    }

    private static function foldUlid(string $value, string $what): string
    {
        if (mb_strlen($value) !== self::ULID_LENGTH || preg_match('/^[0-9A-Za-z]{26}$/', $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] is not a {".self::ULID_LENGTH.'}-character ULID.');
        }

        return strtoupper($value);
    }

    private static function checkAmount(string $value, string $what): string
    {
        if (preg_match(self::SIGNED_AMOUNT_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] must be a DECIMAL(18,4) string.");
        }

        return $value;
    }
}

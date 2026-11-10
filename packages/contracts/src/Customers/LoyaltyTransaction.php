<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

use InvalidArgumentException;

/**
 * A request to record one `loyalty_transactions` row (T-028 plan Ruling 2).
 *
 * **Shape only**, the `CreditLedgerEntry` discipline: this constructor
 * checks that the ids are ULIDs and leaves `points`/`value` to
 * `LoyaltyCalculator` (Ruling 3, Ruling 5). The optional caller-supplied
 * `id` is this task's own idempotency key for loyalty, ahead of FP-233's
 * `sale_id` (F-15): the same id and payload is a replay, the same id with a
 * different payload is `replay_conflict`.
 */
final readonly class LoyaltyTransaction
{
    private const ULID_LENGTH = 26;

    public ?string $id;

    public string $customerId;

    public string $programId;

    public ?string $employeeId;

    public ?string $actorId;

    public function __construct(
        ?string $id,
        string $customerId,
        string $programId,
        public LoyaltyTransactionType $type,
        public int|string $points,
        public int|string|null $value = null,
        public ?string $expiresAt = null,
        ?string $employeeId = null,
        public ?string $notes = null,
        ?string $actorId = null,
    ) {
        $this->id = $id === null ? null : self::foldUlid($id, 'id');
        $this->customerId = self::foldUlid($customerId, 'customerId');
        $this->programId = self::foldUlid($programId, 'programId');
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
}

<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

use InvalidArgumentException;

/**
 * One request to `WorkflowGate::evaluate()` (T-020 Ruling 2).
 *
 * Every check below is a **caller bug**, raised here, before `evaluate()`
 * is ever reached - never `WorkflowEvaluationFailed`, which is reserved for
 * a failure inside the engine itself (Ruling 3).
 *
 * `entityId`, `requestedBy` and `branchId` are folded to upper case on the
 * way in, the T-014 AC-61 convention, because ids are compared under
 * `ascii_bin`. `amount` is never a float - an int, or a decimal string
 * matching DECIMAL(18,4) - so money never passes through binary floating
 * point. A key in `fields` must not collide with one of the reserved keys
 * {@see self::values()} merges in.
 */
final readonly class WorkflowContext
{
    private const ULID_LENGTH = 26;

    /**
     * @var list<string>
     */
    private const RESERVED_FIELD_KEYS = ['amount', 'branch_id', 'entity_id', 'requested_by'];

    public string $entityId;

    public string $requestedBy;

    public ?string $branchId;

    public int|string|null $amount;

    /**
     * @var array<string, string|int|bool|null>
     */
    public array $fields;

    /**
     * @param  array<string, string|int|bool|null>  $fields
     */
    public function __construct(
        string $entityId,
        string $requestedBy,
        ?string $branchId = null,
        int|string|null $amount = null,
        array $fields = [],
    ) {
        $this->entityId = self::foldUlid($entityId, 'entityId');
        $this->requestedBy = self::foldUlid($requestedBy, 'requestedBy');
        $this->branchId = $branchId === null ? null : self::foldUlid($branchId, 'branchId');
        $this->amount = self::checkAmount($amount);
        $this->fields = self::checkFields($fields);
    }

    /**
     * The flat map every condition reads: the caller's own fields, plus the
     * two frozen columns a condition may also name (Ruling 2, Ruling 4).
     *
     * @return array<string, string|int|bool|null>
     */
    public function values(): array
    {
        return array_merge($this->fields, [
            'amount' => $this->amount,
            'branch_id' => $this->branchId,
        ]);
    }

    private static function foldUlid(string $value, string $what): string
    {
        if (mb_strlen($value) !== self::ULID_LENGTH || preg_match('/^[0-9A-Za-z]{26}$/', $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] is not a {".self::ULID_LENGTH.'}-character ULID.');
        }

        return strtoupper($value);
    }

    private static function checkAmount(int|string|null $amount): int|string|null
    {
        if ($amount === null || is_int($amount)) {
            return $amount;
        }

        if (preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $amount) !== 1) {
            throw new InvalidArgumentException('[amount] must be an int or a decimal string DECIMAL(18,4) can hold.');
        }

        return $amount;
    }

    /**
     * @param  array<int|string, mixed>  $fields
     * @return array<string, string|int|bool|null>
     */
    private static function checkFields(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,59}$/', $key) !== 1) {
                throw new InvalidArgumentException("[{$key}] is not a legal field key.");
            }

            if (in_array($key, self::RESERVED_FIELD_KEYS, true)) {
                throw new InvalidArgumentException("[{$key}] is a reserved field key.");
            }

            if (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
                throw new InvalidArgumentException("The value of field [{$key}] must be a string, an int, a bool or null.");
            }
        }

        return $fields;
    }
}

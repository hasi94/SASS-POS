<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Catalog;

use InvalidArgumentException;

/**
 * The `[UOM]` quad `docs/database.md` §3.1 defines for a document line, plus
 * `unit_code` for the three print-bearing lines (D-66) - T-027 plan Ruling 2,
 * Ruling 4.
 *
 * **Shape only, obtained only from `UnitResolver::snapshot()` (or, once a
 * caller earns it, `snapshotInherited()`).** This constructor does not round,
 * does not resolve and does not re-check Q-11's fractional-base rule against
 * any stock unit - it checks that `quantity` and `baseQuantity` are
 * `DECIMAL(18,4)` strings and that `unitFactor` is a `DECIMAL(18,6)` string
 * of at least `1.000000`, exactly {@see ResolvedFactor}'s own guard. A caller
 * that builds this DTO with a `unitFactor` it invented rather than one
 * `UnitResolver` produced is a trust-boundary violation this shape check
 * cannot see - the same limit the contract's own docblock states.
 */
final readonly class UnitSnapshot
{
    private const ULID_LENGTH = 26;

    /** `DECIMAL(18,4)`: up to 14 integer digits, exactly 4 fraction digits, optionally negative. */
    private const AMOUNT_PATTERN = '/^-?\d{1,14}\.\d{4}$/';

    /** `DECIMAL(18,6)`: up to 12 integer digits, exactly 6 fraction digits, never negative, never below 1. */
    private const FACTOR_PATTERN = '/^[1-9]\d{0,11}\.\d{6}$/';

    public string $unitId;

    public string $unitCode;

    public string $quantity;

    public string $unitFactor;

    public string $baseQuantity;

    public function __construct(
        string $unitId,
        string $unitCode,
        string $quantity,
        string $unitFactor,
        string $baseQuantity,
        public FactorSource $source,
    ) {
        $this->unitId = self::foldUlid($unitId, 'unitId');

        if ($unitCode === '' || mb_strlen($unitCode) > 50) {
            throw new InvalidArgumentException('[unitCode] must be 1 to 50 characters.');
        }

        $this->unitCode = $unitCode;
        $this->quantity = self::checkAmount($quantity, 'quantity');
        $this->unitFactor = self::checkFactor($unitFactor);
        $this->baseQuantity = self::checkAmount($baseQuantity, 'baseQuantity');
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
        if (preg_match(self::AMOUNT_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] must be a DECIMAL(18,4) string.");
        }

        return $value;
    }

    private static function checkFactor(string $factor): string
    {
        if (preg_match(self::FACTOR_PATTERN, $factor) !== 1) {
            throw new InvalidArgumentException('[unitFactor] must be a DECIMAL(18,6) string of at least 1.000000.');
        }

        return $factor;
    }
}

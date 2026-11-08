<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Catalog;

use InvalidArgumentException;

/**
 * What `UnitResolver::resolve()` returns: the one factor the conversion
 * ladder found, and which step found it (T-027 plan Ruling 2, Ruling 3).
 *
 * **Shape only.** This constructor never re-derives or re-walks the ladder -
 * it checks that the four ids are ULIDs and that `factor` is a canonical
 * `DECIMAL(18,6)` string of at least `1.000000` (AC-9's own output guard,
 * enforced again here so a malformed or sub-one factor can never be smuggled
 * through by constructing this DTO directly instead of going through the
 * resolver). `factor` is never a float: binary floating point cannot hold
 * `0.000001` exactly, and a float silently rounding to the fourth decimal is
 * the whole reason `brick/math` exists on this task (plan Ruling 5).
 */
final readonly class ResolvedFactor
{
    private const ULID_LENGTH = 26;

    /** `DECIMAL(18,6)`: up to 12 integer digits, exactly 6 fraction digits, never negative, never below 1. */
    private const FACTOR_PATTERN = '/^[1-9]\d{0,11}\.\d{6}$/';

    public string $productId;

    public string $fromUnitId;

    public string $stockUnitId;

    public string $factor;

    public ?string $conversionId;

    public function __construct(
        string $productId,
        string $fromUnitId,
        string $stockUnitId,
        string $factor,
        public FactorSource $source,
        ?string $conversionId = null,
    ) {
        $this->productId = self::foldUlid($productId, 'productId');
        $this->fromUnitId = self::foldUlid($fromUnitId, 'fromUnitId');
        $this->stockUnitId = self::foldUlid($stockUnitId, 'stockUnitId');
        $this->factor = self::checkFactor($factor);
        $this->conversionId = $conversionId === null ? null : self::foldUlid($conversionId, 'conversionId');
    }

    private static function foldUlid(string $value, string $what): string
    {
        if (mb_strlen($value) !== self::ULID_LENGTH || preg_match('/^[0-9A-Za-z]{26}$/', $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] is not a {".self::ULID_LENGTH.'}-character ULID.');
        }

        return strtoupper($value);
    }

    private static function checkFactor(string $factor): string
    {
        if (preg_match(self::FACTOR_PATTERN, $factor) !== 1) {
            throw new InvalidArgumentException('[factor] must be a DECIMAL(18,6) string of at least 1.000000.');
        }

        return $factor;
    }
}

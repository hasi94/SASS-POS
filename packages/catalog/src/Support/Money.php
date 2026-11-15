<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Support;

use Flexipos\Catalog\Exceptions\CatalogRefused;

/**
 * Money, exactly as T-027's own tables store it: `DECIMAL(18,4)`, an int or
 * a plain decimal string, never a float (plan Ruling 8).
 *
 * A thin, named wrapper over {@see Fields::decimal()} at the one scale this
 * package's money columns share, so a handler reads "a money field" rather
 * than repeating the literal `4` at every call site.
 */
final class Money
{
    public const SCALE = 4;

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws CatalogRefused validation
     */
    public static function amount(array $fields, string $key, bool $allowNegative = false, ?string $default = null): ?string
    {
        return Fields::decimal($fields, $key, self::SCALE, $allowNegative, $default);
    }
}

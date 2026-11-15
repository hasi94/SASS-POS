<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Pricing\Shape;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use InvalidArgumentException;

/**
 * Reading submitted fields, in one place (the catalog `Fields` precedent).
 *
 * **A wrong type is `validation`, never a `TypeError`**, and a field the
 * operation does not take is `validation` too, never silently ignored: a handler
 * that picked out the fields it understood would turn "you may not change the
 * code" into a quiet success that changed nothing. Every handler hands the whole
 * submitted array to {@see self::only()} first.
 */
final class Fields
{
    /**
     * Refuse any field outside the allowed set.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     *
     * @throws PricingAdminRefused validation
     */
    public static function only(array $fields, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($fields)), $allowed));

        if ($unknown !== []) {
            throw PricingAdminRefused::validation('These fields are not accepted here: '.implode(', ', $unknown).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function has(array $fields, string $key): bool
    {
        return array_key_exists($key, $fields);
    }

    /**
     * A required string, trimmed, non-empty and within its width.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function requiredText(array $fields, string $key, int $max): string
    {
        $value = $fields[$key] ?? null;

        if (! is_string($value)) {
            throw PricingAdminRefused::validation("The {$key} is required and must be a string.");
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            throw PricingAdminRefused::validation("The {$key} must be 1 to {$max} characters.");
        }

        return $value;
    }

    /**
     * An optional string: null when absent or null, refused when present and not
     * a string, trimmed to null when blank.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function optionalText(array $fields, string $key, int $max): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw PricingAdminRefused::validation("The {$key} must be a string or null.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw PricingAdminRefused::validation("The {$key} must be at most {$max} characters.");
        }

        return $value;
    }

    /**
     * A strict boolean: `"true"` is a string and is refused.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function flag(array $fields, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        if (! is_bool($fields[$key])) {
            throw PricingAdminRefused::validation("The {$key} must be true or false.");
        }

        return $fields[$key];
    }

    /**
     * An integer within `[$min, $max]`; `$default` when the key is absent.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function integer(array $fields, string $key, int $default, int $min, int $max): int
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if (! is_int($value) || $value < $min || $value > $max) {
            throw PricingAdminRefused::validation("The {$key} must be an integer from {$min} to {$max}.");
        }

        return $value;
    }

    /**
     * An optional non-negative integer, null when absent or null.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function optionalInteger(array $fields, string $key, int $max): ?int
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 0 || $value > $max) {
            throw PricingAdminRefused::validation("The {$key} must be null or an integer from 0 to {$max}.");
        }

        return $value;
    }

    /**
     * An int or a plain non-negative decimal string of at most `$scale`
     * decimals, never a float and never more than `$integerDigits` integer
     * digits (the column's own width). `$default` when the key is absent, null
     * when it is present and null.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function decimal(array $fields, string $key, int $integerDigits, int $scale, ?string $default = null): ?string
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw PricingAdminRefused::validation("The {$key} must be an int or a decimal string.");
        }

        if (preg_match('/^\d{1,'.$integerDigits.'}(\.\d{1,'.$scale.'})?$/D', $value) !== 1) {
            throw PricingAdminRefused::validation("The {$key} must be a non-negative decimal of at most {$integerDigits} integer and {$scale} decimal digits.");
        }

        return $value;
    }

    /**
     * A money or quantity column, `DECIMAL(18,4)`.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function money(array $fields, string $key, ?string $default = null): ?string
    {
        return self::decimal($fields, $key, 14, 4, $default);
    }

    /**
     * A rate or percentage column, `DECIMAL(9,4)`.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function rate(array $fields, string $key, ?string $default = null): ?string
    {
        return self::decimal($fields, $key, 5, 4, $default);
    }

    /**
     * Whether two non-negative decimal strings are the same number: `1` and
     * `1.0000` are one value, so they are compared numerically and never as text.
     */
    public static function sameNumber(string $a, string $b): bool
    {
        return BigDecimal::of($a)->compareTo(BigDecimal::of($b)) === 0;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     *
     * @throws PricingAdminRefused validation
     */
    public static function enum(array $fields, string $key, array $allowed, ?string $default): ?string
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw PricingAdminRefused::validation("The {$key} must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    /**
     * A calendar date, `Y-m-d`.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function date(array $fields, string $key, bool $required): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            if ($required) {
                throw PricingAdminRefused::validation("The {$key} is required.");
            }

            return null;
        }

        try {
            return is_string($value) ? Shape::date($value, $key) : throw new InvalidArgumentException('not a string');
        } catch (InvalidArgumentException) {
            throw PricingAdminRefused::validation("The {$key} must be a valid Y-m-d date.");
        }
    }

    /**
     * An optional UTC instant in the engine's own format,
     * `YYYY-MM-DDTHH:MM:SS.mmmZ`, returned as the `DATETIME(3)` string the
     * tenant database stores (`Y-m-d H:i:s.v`).
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PricingAdminRefused validation
     */
    public static function instant(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        try {
            $valid = is_string($value) ? Shape::instant($value, $key) : throw new InvalidArgumentException('not a string');
        } catch (InvalidArgumentException) {
            throw PricingAdminRefused::validation("The {$key} must be a UTC instant, YYYY-MM-DDTHH:MM:SS.mmmZ.");
        }

        return str_replace(['T', 'Z'], [' ', ''], $valid);
    }
}

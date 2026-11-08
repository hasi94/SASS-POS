<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Support;

use Flexipos\Catalog\Exceptions\CatalogRefused;

/**
 * Reading submitted fields, in one place (plan Ruling 10, the organization
 * precedent).
 *
 * **A wrong type is `validation`, never a `TypeError`**, and a field the
 * operation does not take is `validation` too, never silently ignored: a
 * handler that picked out the fields it understood would turn "you may not
 * change the code" into a quiet success that changed nothing. Every handler
 * hands the whole submitted array to {@see self::only()} first.
 */
final class Fields
{
    /**
     * Refuse any field outside the allowed set.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     *
     * @throws CatalogRefused validation
     */
    public static function only(array $fields, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($fields)), $allowed));

        if ($unknown !== []) {
            throw CatalogRefused::validation('These fields are not accepted here: '.implode(', ', $unknown).'.');
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
     * @throws CatalogRefused validation
     */
    public static function requiredText(array $fields, string $key, int $max): string
    {
        $value = $fields[$key] ?? null;

        if (! is_string($value)) {
            throw CatalogRefused::validation("The {$key} is required and must be a string.");
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            throw CatalogRefused::validation("The {$key} must be 1 to {$max} characters.");
        }

        return $value;
    }

    /**
     * An optional string: null when absent or null, refused when present
     * and not a string, trimmed to null when blank.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CatalogRefused validation
     */
    public static function optionalText(array $fields, string $key, int $max): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw CatalogRefused::validation("The {$key} must be a string or null.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw CatalogRefused::validation("The {$key} must be at most {$max} characters.");
        }

        return $value;
    }

    /**
     * A strict boolean: `"true"` is a string and is refused.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CatalogRefused validation
     */
    public static function flag(array $fields, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        if (! is_bool($fields[$key])) {
            throw CatalogRefused::validation("The {$key} must be true or false.");
        }

        return $fields[$key];
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws CatalogRefused validation
     */
    public static function integer(array $fields, string $key, int $default): int
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if (! is_int($value) || $value < -2147483648 || $value > 2147483647) {
            throw CatalogRefused::validation("The {$key} must be an integer.");
        }

        return $value;
    }

    /**
     * An int or a plain decimal string of at most `$scale` decimals, never
     * a float. Required when `$default` is null and the key is absent.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CatalogRefused validation
     */
    public static function decimal(array $fields, string $key, int $scale, bool $allowNegative = false, ?string $default = null): ?string
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
            throw CatalogRefused::validation("The {$key} must be an int or a decimal string.");
        }

        $pattern = $allowNegative ? '/^-?\d+(\.\d{1,'.$scale.'})?$/' : '/^\d+(\.\d{1,'.$scale.'})?$/';

        if (preg_match($pattern, $value) !== 1) {
            throw CatalogRefused::validation("The {$key} must be a decimal with at most {$scale} decimal places".($allowNegative ? '.' : ', and not negative.'));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     *
     * @throws CatalogRefused validation
     */
    public static function enum(array $fields, string $key, array $allowed, ?string $default): ?string
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw CatalogRefused::validation("The {$key} must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }
}

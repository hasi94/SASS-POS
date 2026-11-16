<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Suppliers;

use Flexipos\Purchasing\Support\Money;
use Flexipos\Purchasing\Support\Redaction;
use stdClass;

/**
 * A supplier row as one normalised array, and its audit images (T-034 AC-6,
 * AC-15, AC-17, Ruling 10).
 *
 * The SQLite stand-in hands decimals, booleans and JSON back in whatever shape
 * its column affinity chose; MySQL hands them back as strings. {@see self::of()}
 * makes both one shape (a four-decimal string, an int, a bool, a decoded object),
 * so a comparison, a result and an audit image mean the same on either engine.
 *
 * **The audit images are the only place `bank_details` is hidden** ({@see Redaction}):
 * the row a manager reads carries it, the reader holding only `view` is given the
 * row without the key at all, and no audit row ever carries its content.
 */
final class SupplierImage
{
    /** The JSON columns, decoded in the image and compared as objects. */
    public const JSON = ['address', 'bank_details'];

    /**
     * @param  object|array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function of(object|array $row): array
    {
        $a = (array) $row;

        return [
            'id' => strtoupper((string) $a['id']),
            'created_at' => (string) $a['created_at'],
            'updated_at' => (string) $a['updated_at'],
            'deleted_at' => self::text($a['deleted_at'] ?? null),
            'created_by' => self::id($a['created_by'] ?? null),
            'updated_by' => self::id($a['updated_by'] ?? null),
            'code' => (string) $a['code'],
            'name' => (string) $a['name'],
            'legal_name' => self::text($a['legal_name'] ?? null),
            'contact_name' => self::text($a['contact_name'] ?? null),
            'phone' => self::text($a['phone'] ?? null),
            'email' => self::text($a['email'] ?? null),
            'address' => self::object($a['address'] ?? null),
            'tax_no' => self::text($a['tax_no'] ?? null),
            'currency' => self::text($a['currency'] ?? null),
            'payment_terms_days' => (int) $a['payment_terms_days'],
            'credit_limit' => Money::of($a['credit_limit']),
            'bank_details' => self::object($a['bank_details'] ?? null),
            'is_active' => (bool) $a['is_active'],
            'notes' => self::text($a['notes'] ?? null),
        ];
    }

    /**
     * The image a reader holding only `purchasing.suppliers.view` may see (AC-15):
     * the key is absent, not null, so the answer does not say whether there are
     * bank details.
     *
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    public static function withoutBankDetails(array $image): array
    {
        unset($image[Redaction::FIELD]);

        return $image;
    }

    /**
     * The before image of an audit row: `bank_details` hidden.
     *
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    public static function auditBefore(array $image): array
    {
        return Redaction::before($image) ?? $image;
    }

    /**
     * The after image of an audit row: `bank_details` hidden, marked changed when
     * it differs from the before image (`null` before is a create).
     *
     * @param  array<string, mixed>|null  $before  the raw stored image
     * @param  array<string, mixed>  $after  the raw new image
     * @return array<string, mixed>
     */
    public static function auditAfter(?array $before, array $after): array
    {
        return Redaction::after($before, $after) ?? $after;
    }

    /**
     * Whether a submitted value differs from the stored one in this column. JSON
     * objects are compared as objects (key order is not a difference).
     */
    public static function differs(string $column, mixed $stored, mixed $submitted): bool
    {
        if (in_array($column, self::JSON, true)) {
            return self::canonical($stored) !== self::canonical($submitted);
        }

        return $stored !== $submitted;
    }

    /**
     * The JSON text a JSON column is written as; an empty object stays `{}`.
     *
     * @param  array<string, mixed>|null  $value
     */
    public static function encode(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value === [] ? new stdClass : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function object(mixed $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) ? $decoded : null;
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : strtoupper((string) $value);
    }

    private static function canonical(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}

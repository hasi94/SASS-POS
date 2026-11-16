<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Suppliers;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Money;

/**
 * The submitted supplier fields, validated and normalised (T-034 AC-4 to AC-6).
 *
 * One place for create and update, so the two cannot disagree about a rule. On a
 * create every column gets a value (a default where the caller gave none); on an
 * update only the keys the caller submitted come back, so "left out" and "set to
 * null" stay different things. The code is the caller's to name on create and
 * immutable after (AC-11): this class normalises it, the update slice compares it.
 *
 * **Folding.** Text is trimmed (a blank optional text is null), `email` is lower
 * case, `currency` and `tax_no` upper case. A JSON column takes an object, never a
 * list or a scalar; an empty object is stored as `{}`.
 */
final class SupplierFields
{
    /** Every field either supplier slice accepts: a caller-supplied `id` is not one (AC-1, Q-B3). */
    public const ACCEPTED = [
        'code', 'name', 'legal_name', 'contact_name', 'phone', 'email', 'address', 'tax_no',
        'currency', 'payment_terms_days', 'credit_limit', 'bank_details', 'notes',
    ];

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed> column => value; `address` and `bank_details` as arrays (or null)
     *
     * @throws PurchasingRefused validation, out_of_range
     */
    public static function parse(array $fields, bool $create): array
    {
        $parsed = [];

        if ($create || array_key_exists('code', $fields)) {
            $parsed['code'] = SupplierCode::normalise($fields['code'] ?? null);
        }

        if ($create || array_key_exists('name', $fields)) {
            $parsed['name'] = Fields::requiredText($fields, 'name', 150);
        }

        foreach (['legal_name' => 150, 'contact_name' => 100, 'phone' => 30, 'notes' => 2000] as $column => $max) {
            if ($create || array_key_exists($column, $fields)) {
                $parsed[$column] = Fields::optionalText($fields, $column, $max);
            }
        }

        if ($create || array_key_exists('email', $fields)) {
            $parsed['email'] = self::email($fields);
        }

        if ($create || array_key_exists('tax_no', $fields)) {
            $taxNo = Fields::optionalText($fields, 'tax_no', 50);
            $parsed['tax_no'] = $taxNo === null ? null : strtoupper($taxNo);
        }

        if ($create || array_key_exists('currency', $fields)) {
            $parsed['currency'] = self::currency($fields);
        }

        foreach (['address', 'bank_details'] as $column) {
            if ($create || array_key_exists($column, $fields)) {
                $parsed[$column] = self::object($fields, $column);
            }
        }

        if (array_key_exists('payment_terms_days', $fields)) {
            $parsed['payment_terms_days'] = Fields::integer($fields, 'payment_terms_days', 0, 0, 3650);
        } elseif ($create) {
            $parsed['payment_terms_days'] = 0;
        }

        if (array_key_exists('credit_limit', $fields)) {
            $parsed['credit_limit'] = self::creditLimit($fields['credit_limit']);
        } elseif ($create) {
            $parsed['credit_limit'] = Money::ZERO;
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws PurchasingRefused validation
     */
    private static function email(array $fields): ?string
    {
        $email = Fields::optionalText($fields, 'email', 191);

        if ($email === null) {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw PurchasingRefused::validation('The email is not a well-formed address.');
        }

        return strtolower($email);
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws PurchasingRefused validation
     */
    private static function currency(array $fields): ?string
    {
        $value = $fields['currency'] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^[A-Za-z]{3}$/D', trim($value)) !== 1) {
            throw PurchasingRefused::validation('The currency must be three letters.');
        }

        return strtoupper(trim($value));
    }

    /**
     * A JSON object, or null. A list or a scalar is not an object.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>|null
     *
     * @throws PurchasingRefused validation
     */
    private static function object(array $fields, string $key): ?array
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw PurchasingRefused::validation("The {$key} must be a JSON object.");
        }

        return $value;
    }

    /**
     * A non-negative decimal string or int of at most four decimals (AC-6): a
     * float, a negative, null and five decimals are `validation`; more than 14
     * integer digits is `out_of_range`, because it is a well-formed number the
     * column cannot hold.
     *
     * @throws PurchasingRefused validation, out_of_range
     */
    private static function creditLimit(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^(\d+)(\.\d{1,4})?$/D', $value, $parts) !== 1) {
            throw PurchasingRefused::validation('The credit_limit must be a non-negative decimal string with at most 4 decimals.');
        }

        if (strlen(ltrim($parts[1], '0')) > 14) {
            throw PurchasingRefused::outOfRange('The credit_limit does not fit DECIMAL(18,4).');
        }

        return Money::of($value);
    }
}

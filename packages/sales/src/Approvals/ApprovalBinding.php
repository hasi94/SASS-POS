<?php

declare(strict_types=1);

namespace Flexipos\Sales\Approvals;

use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Money;
use InvalidArgumentException;

/**
 * What an approval is bound to (D-47, F-21), in one place: the request slice
 * checks a submission against it, and {@see ApprovalGate} compares a stored row
 * against what a write is about to do.
 *
 * | type | reference_type | reference_id | payload |
 * |---|---|---|---|
 * | void | `sale` | the sale | none |
 * | refund | `sale` or `goodwill_refund` | the original sale, or null | none |
 * | price_override | `sale` | the sale | `{product_id, unit_id, unit_price}` |
 * | discount | `sale` | the sale | `{discount_id}` |
 *
 * A payload is stored canonical (ids upper-case, the price at four decimals) so
 * that the comparison is by value: `7.5` is `7.5000`, and an id is the same id in
 * any case (R-8, R-38).
 */
final class ApprovalBinding
{
    public const SALE = 'sale';

    public const GOODWILL_REFUND = 'goodwill_refund';

    private const ID_KEYS = ['product_id', 'unit_id', 'discount_id'];

    /**
     * @return list<string>
     */
    public static function payloadKeys(ApprovalType $type): array
    {
        return match ($type) {
            ApprovalType::PriceOverride => ['product_id', 'unit_id', 'unit_price'],
            ApprovalType::Discount => ['discount_id'],
            ApprovalType::Void, ApprovalType::Refund => [],
        };
    }

    /**
     * @throws SalesRefused validation when the reference is not one this type may be bound to
     */
    public static function assertReference(ApprovalType $type, string $referenceType, ?string $referenceId): void
    {
        $goodwill = $type === ApprovalType::Refund && $referenceType === self::GOODWILL_REFUND && $referenceId === null;
        $sale = $referenceType === self::SALE && $referenceId !== null;

        if (! $goodwill && ! $sale) {
            throw SalesRefused::validation("A [{$type->value}] approval is not bound to a [{$referenceType}] reference like that.");
        }
    }

    /**
     * The payload as it is stored, or null for a type that carries none.
     *
     * @return array<string, string>|null
     *
     * @throws SalesRefused validation
     */
    public static function canonicalPayload(ApprovalType $type, mixed $payload): ?array
    {
        $keys = self::payloadKeys($type);

        if ($keys === []) {
            if ($payload !== null) {
                throw SalesRefused::validation("A [{$type->value}] approval carries no payload.");
            }

            return null;
        }

        if (! is_array($payload) || array_diff(array_map('strval', array_keys($payload)), $keys) !== [] || array_diff($keys, array_keys($payload)) !== []) {
            throw SalesRefused::validation("A [{$type->value}] approval's payload must be exactly: ".implode(', ', $keys).'.');
        }

        $canonical = [];

        foreach ($keys as $key) {
            $value = $payload[$key];

            if ($key === 'unit_price') {
                $canonical[$key] = self::price($value);

                continue;
            }

            $canonical[$key] = Identifier::fold($value, $key);
        }

        return $canonical;
    }

    /**
     * Whether a stored payload and the one a write is about to use are the same
     * facts. A malformed or unexpected value is a mismatch, never an exception.
     *
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>|null  $wanted
     */
    public static function payloadMatches(?array $stored, ?array $wanted): bool
    {
        $stored ??= [];
        $wanted ??= [];

        if (array_diff(array_map('strval', array_keys($stored)), array_map('strval', array_keys($wanted))) !== []
            || array_diff(array_map('strval', array_keys($wanted)), array_map('strval', array_keys($stored))) !== []) {
            return false;
        }

        foreach ($stored as $key => $value) {
            $other = $wanted[$key];

            if (in_array($key, self::ID_KEYS, true)) {
                if (! is_string($value) || ! is_string($other) || strtoupper($value) !== strtoupper($other)) {
                    return false;
                }

                continue;
            }

            if ($key !== 'unit_price') {
                return false;
            }

            try {
                if (! (is_string($value) || is_int($value)) || ! (is_string($other) || is_int($other)) || ! Money::equals((string) $value, (string) $other)) {
                    return false;
                }
            } catch (InvalidArgumentException) {
                return false;
            }
        }

        return true;
    }

    private static function price(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^\d{1,14}(\.\d{1,4})?$/D', $value) !== 1) {
            throw SalesRefused::validation('The unit_price must be a non-negative decimal of at most 14 integer and 4 decimal digits.');
        }

        return Money::of($value);
    }
}

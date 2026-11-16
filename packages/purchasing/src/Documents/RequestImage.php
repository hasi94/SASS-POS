<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Flexipos\Purchasing\Support\Money;

/**
 * A purchase request header or line as one normalised array (T-034 AC-34, AC-39).
 *
 * The SQLite stand-in hands back decimals as ints or floats and MySQL as strings;
 * {@see self::header()} and {@see self::line()} make both one shape (ids upper
 * case, a four-decimal string for a quantity or a cost, a six-decimal string for a
 * unit factor, an int for a line number), so a result, a comparison and an audit
 * image mean the same on either engine. The images are what the audit rows carry:
 * a header image never holds its lines, and a line change is recorded against its
 * header with the line's own image (D-23).
 */
final class RequestImage
{
    /**
     * @param  object|array<string, mixed>  $row  a `purchase_requests` row
     * @return array<string, mixed>
     */
    public static function header(object|array $row): array
    {
        $a = (array) $row;

        return [
            'id' => strtoupper((string) $a['id']),
            'created_at' => (string) $a['created_at'],
            'updated_at' => (string) $a['updated_at'],
            'created_by' => self::id($a['created_by'] ?? null),
            'updated_by' => self::id($a['updated_by'] ?? null),
            'number' => (string) $a['number'],
            'branch_id' => strtoupper((string) $a['branch_id']),
            'location_id' => strtoupper((string) $a['location_id']),
            'requested_by' => strtoupper((string) $a['requested_by']),
            'required_date' => $a['required_date'] === null ? null : substr((string) $a['required_date'], 0, 10),
            'status' => (string) $a['status'],
            'workflow_instance_id' => self::id($a['workflow_instance_id'] ?? null),
            'notes' => $a['notes'] === null ? null : (string) $a['notes'],
        ];
    }

    /**
     * @param  object|array<string, mixed>  $row  a `purchase_request_items` row
     * @return array<string, mixed>
     */
    public static function line(object|array $row): array
    {
        $a = (array) $row;

        return [
            'id' => strtoupper((string) $a['id']),
            'request_id' => strtoupper((string) $a['request_id']),
            'line_no' => (int) $a['line_no'],
            'product_id' => strtoupper((string) $a['product_id']),
            'unit_id' => strtoupper((string) $a['unit_id']),
            'quantity' => Money::of($a['quantity']),
            'unit_factor' => self::factor($a['unit_factor']),
            'base_quantity' => Money::of($a['base_quantity']),
            'estimated_cost' => $a['estimated_cost'] === null ? null : Money::of($a['estimated_cost']),
            'supplier_id' => self::id($a['supplier_id'] ?? null),
            'purchase_order_item_id' => self::id($a['purchase_order_item_id'] ?? null),
        ];
    }

    /**
     * A unit factor as a six-decimal string (`DECIMAL(18,6)`), the width the
     * resolver freezes it at.
     */
    public static function factor(int|float|string $stored): string
    {
        return BigDecimal::of((string) $stored)->toScale(6, RoundingMode::HalfUp)->__toString();
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : strtoupper((string) $value);
    }
}

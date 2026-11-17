<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Support\Money;

/**
 * A purchase order header or line as one normalised array (T-034 AC-40, AC-81,
 * AC-48, AC-87).
 *
 * The SQLite stand-in hands decimals back as ints or floats and MySQL as strings;
 * {@see self::header()} and {@see self::line()} make both one shape (ids upper
 * case, a four-decimal string for money and quantities, an eight-decimal string
 * for the exchange rate, a six-decimal one for a unit factor, an int for a line
 * number or the payment terms), so a result, a comparison and an audit image mean
 * the same on either engine. **Nothing here computes**: a stored line is returned
 * as it was frozen (AC-48). A header image never holds its lines; a line change is
 * recorded against its header with the line's own image (D-23).
 */
final class OrderImage
{
    /**
     * @param  object|array<string, mixed>  $row  a `purchase_orders` row
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
            'supplier_id' => strtoupper((string) $a['supplier_id']),
            'supplier_name' => (string) $a['supplier_name'],
            'supplier_tax_no' => $a['supplier_tax_no'] === null ? null : (string) $a['supplier_tax_no'],
            'payment_terms_days' => (int) $a['payment_terms_days'],
            'branch_id' => strtoupper((string) $a['branch_id']),
            'location_id' => strtoupper((string) $a['location_id']),
            'request_id' => self::id($a['request_id'] ?? null),
            'order_date' => substr((string) $a['order_date'], 0, 10),
            'expected_date' => $a['expected_date'] === null ? null : substr((string) $a['expected_date'], 0, 10),
            'status' => (string) $a['status'],
            'workflow_instance_id' => self::id($a['workflow_instance_id'] ?? null),
            'currency' => strtoupper(trim((string) $a['currency'])),
            'exchange_rate' => ExchangeRate::of($a['exchange_rate']),
            'subtotal' => Money::of($a['subtotal']),
            'discount_total' => Money::of($a['discount_total']),
            'tax_total' => Money::of($a['tax_total']),
            'shipping' => Money::of($a['shipping']),
            'total' => Money::of($a['total']),
            'received_value' => Money::of($a['received_value']),
            'terms' => $a['terms'] === null ? null : (string) $a['terms'],
            'notes' => $a['notes'] === null ? null : (string) $a['notes'],
        ];
    }

    /**
     * @param  object|array<string, mixed>  $row  a `purchase_order_items` row
     * @return array<string, mixed>
     */
    public static function line(object|array $row): array
    {
        $a = (array) $row;

        return [
            'id' => strtoupper((string) $a['id']),
            'order_id' => strtoupper((string) $a['order_id']),
            'line_no' => (int) $a['line_no'],
            'product_id' => strtoupper((string) $a['product_id']),
            'description' => (string) $a['description'],
            'unit_id' => strtoupper((string) $a['unit_id']),
            'quantity' => Money::of($a['quantity']),
            'unit_factor' => RequestImage::factor($a['unit_factor']),
            'base_quantity' => Money::of($a['base_quantity']),
            'unit_cost' => Money::of($a['unit_cost']),
            'subtotal' => Money::of($a['subtotal']),
            'discount_percent' => Money::of($a['discount_percent']),
            'discount_amount' => Money::of($a['discount_amount']),
            'tax_group_id' => strtoupper((string) $a['tax_group_id']),
            'tax_amount' => Money::of($a['tax_amount']),
            'line_total' => Money::of($a['line_total']),
            'received_quantity' => Money::of($a['received_quantity']),
            'received_base_quantity' => Money::of($a['received_base_quantity']),
            'expected_date' => $a['expected_date'] === null ? null : substr((string) $a['expected_date'], 0, 10),
        ];
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : strtoupper((string) $value);
    }
}

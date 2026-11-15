<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tenders;

use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Support\Fields;
use Illuminate\Database\Connection;

/**
 * The fields of a payment method and how each is read (AC-16), shared by the
 * create and update slices so the two cannot disagree about what a valid value
 * is.
 *
 * `financial_account_id`, `gateway` and `settings` are not here: they are not
 * built columns at all (D-6, D-33, F-8), so they are refused as unknown rather
 * than silently dropped. `version` is the system's, and `id`, `deleted_at` and
 * the timestamps are not fields.
 */
final class PaymentMethodFields
{
    /**
     * @var list<string>
     */
    public const FIELDS = [
        'code', 'name', 'type', 'is_active', 'opens_drawer', 'requires_reference',
        'allows_change', 'allows_refund', 'allows_partial', 'rounding_rule', 'surcharge_percent', 'sort_order',
    ];

    /**
     * The column values for the fields that are present, validated. A field that
     * is absent is not in the result, so an update changes only what it names.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     *
     * @throws SalesRefused validation
     */
    public static function read(array $fields): array
    {
        Fields::only($fields, self::FIELDS);

        $read = [];

        foreach (['code' => 50, 'name' => 150] as $key => $width) {
            if (array_key_exists($key, $fields)) {
                $read[$key] = Fields::requiredText($fields, $key, $width);
            }
        }

        if (array_key_exists('type', $fields)) {
            $read['type'] = Fields::enum($fields, 'type', MethodType::values(), null);
        }

        foreach (['is_active', 'opens_drawer', 'requires_reference', 'allows_change', 'allows_refund', 'allows_partial'] as $flag) {
            if (array_key_exists($flag, $fields)) {
                $read[$flag] = Fields::flag($fields, $flag, false);
            }
        }

        if (array_key_exists('rounding_rule', $fields)) {
            $read['rounding_rule'] = Fields::optionalText($fields, 'rounding_rule', 30);
        }

        if (array_key_exists('surcharge_percent', $fields)) {
            $read['surcharge_percent'] = Fields::rate($fields, 'surcharge_percent');
        }

        if (array_key_exists('sort_order', $fields)) {
            $read['sort_order'] = Fields::integer($fields, 'sort_order', 0, -2147483648, 2147483647);
        }

        return $read;
    }

    /**
     * How many tenders on a `draft`, `held` or `pending_payment` sale name this
     * method: the reference guard of AC-16, shared by the deactivating update and
     * the delete. A plain read; the caller holds the method row's lock.
     */
    public static function openTenders(Connection $connection, string $paymentMethodId): int
    {
        return $connection->table('sale_tenders')
            ->join('sales', 'sales.id', '=', 'sale_tenders.sale_id')
            ->where('sale_tenders.payment_method_id', $paymentMethodId)
            ->whereIn('sales.status', SaleStatus::open())
            ->count();
    }
}

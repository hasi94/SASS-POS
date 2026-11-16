<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Support;

use Flexipos\Pricing\Tests\Support\PricingSeed;
use Illuminate\Support\Str;

/**
 * The till a cart test stands at: a branch with a terminal, a cashier, an open
 * shift, and priced products with a tax group (T-030 chunk C).
 *
 * A class and not file-level functions, because Pest declares a test file's
 * functions globally and two files naming one function is a fatal error in a
 * single run. Everything is written through {@see SalesSeed} and
 * {@see PricingSeed}: direct inserts, never another slice's handler.
 */
final class SalesWorld
{
    /**
     * A branch, a terminal with its location, a cashier and an open shift on the
     * terminal.
     *
     * @return array{branch: string, terminal: string, location: string, cashier: string, shift: string}
     */
    public static function till(string $terminalCode = 'T1', ?string $branchId = null, ?string $cashierId = null): array
    {
        $branch = $branchId ?? SalesSeed::branch();
        $terminal = SalesSeed::terminal($branch, $terminalCode);
        $cashier = $cashierId ?? SalesSeed::employee('cashier'.$terminalCode);

        return [
            'branch' => $branch,
            'terminal' => $terminal['id'],
            'location' => $terminal['location'],
            'cashier' => $cashier,
            'shift' => SalesSeed::shift($terminal['id'], $branch, $cashier, 'SH9'.$terminalCode.substr($terminal['id'], -6)),
        ];
    }

    /**
     * A second terminal in the same branch, with its own open shift.
     *
     * @param  array{branch: string, cashier: string}  $till
     * @return array{branch: string, terminal: string, location: string, cashier: string, shift: string}
     */
    public static function secondTill(array $till, string $terminalCode = 'T2'): array
    {
        return self::till($terminalCode, $till['branch'], $till['cashier']);
    }

    /**
     * A tax group with one percentage tax at `$rate` percent, effective for
     * the whole of the fixtures' time.
     */
    public static function taxGroup(string $code, string $rate, bool $inclusive = false): string
    {
        $authority = PricingSeed::authority('A'.$code);
        $tax = PricingSeed::tax($authority, 'X'.$code);
        PricingSeed::rate($tax, $rate, '2020-01-01');

        $group = PricingSeed::group($code, ['is_inclusive' => $inclusive]);
        PricingSeed::member($group, $tax, 1);

        return $group;
    }

    /**
     * A product priced from its own selling price, in a unit of its own, on a tax
     * group.
     *
     * @param  array<string, mixed>  $productOverrides
     * @return array{product: string, unit: string, taxGroup: string}
     */
    public static function product(string $sku, string $price, ?string $taxGroup = null, array $productOverrides = []): array
    {
        $unit = PricingSeed::unit('U'.$sku);
        $group = $taxGroup ?? PricingSeed::group('G'.$sku);

        $product = PricingSeed::product($sku, $unit, array_merge([
            'selling_price' => $price,
            'tax_group_id' => $group,
        ], $productOverrides));

        return ['product' => $product, 'unit' => $unit, 'taxGroup' => $group];
    }

    /**
     * A draft sale on the till's terminal and open shift, at zero.
     *
     * @param  array{branch: string, terminal: string, location: string, cashier: string, shift: string}  $till
     * @param  array<string, mixed>  $overrides
     */
    public static function sale(array $till, array $overrides = []): string
    {
        return SalesSeed::sale($till['branch'], $till['location'], $till['cashier'], array_merge([
            'terminal_id' => $till['terminal'],
            'shift_id' => $till['shift'],
        ], $overrides));
    }

    /**
     * A conversion row: one `$from` is `$factor` of `$to`, for a product or (null) for every product.
     */
    public static function conversion(string $from, string $to, string $factor, ?string $productId = null): string
    {
        $id = (string) Str::ulid();

        SalesSeed::connection()->table('unit_conversions')->insert([
            'id' => $id,
            'product_id' => $productId,
            'from_unit_id' => $from,
            'to_unit_id' => $to,
            'factor' => $factor,
        ]);

        return $id;
    }

    /**
     * A customer with a name, a phone and a tax number to snapshot.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function customer(string $name = 'Ada', array $overrides = []): string
    {
        $id = PricingSeed::customer($name);

        SalesSeed::connection()->table('customers')->where('id', $id)->update(array_merge([
            'phone' => '0771234567',
            'tax_no' => 'TAX-1',
        ], $overrides));

        return $id;
    }

    /**
     * A customer who may buy on credit in the branch currency.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function creditCustomer(string $name = 'Credit', string $limit = '1000.0000', array $overrides = []): string
    {
        return self::customer($name, array_merge([
            'currency' => 'LKR',
            'credit_allowed' => true,
            'credit_limit' => $limit,
        ], $overrides));
    }
}

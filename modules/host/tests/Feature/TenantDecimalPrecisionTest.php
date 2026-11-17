<?php

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;

/*
|--------------------------------------------------------------------------
| Every DECIMAL column matches its documented precision (T-027 AC-44,
| docs/database.md section 3.2)
|--------------------------------------------------------------------------
|
| The bare-decimal() half ("a decimal column states its precision") is
| already SourceRules AC-35(a); this test cites that rule rather than
| duplicating it, and adds the scale check AC-35(a) does not make: every
| `decimal()`/`unsignedDecimal()` call in the whole tenant tree names one of
| the four documented (precision, scale) pairs - money and quantities
| (18,4), a conversion factor (18,6), a percentage (9,4), an exchange rate
| (18,8) - and T-027's own twelve files match section 4's column-by-column
| map exactly.
*/

/**
 * Every `decimal`/`unsignedDecimal` call across the whole tenant tree, as
 * `file => [[column, precision, scale], ...]`.
 *
 * @return array<string, list<array{0: string, 1: int, 2: int}>>
 */
function tenantDecimalCalls(): array
{
    $calls = [];

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/*.php') as $file) {
        $code = SourceRules::codeOnly((string) file_get_contents($file));

        if (preg_match_all('/->\s*(?:decimal|unsignedDecimal)\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/', $code, $matches, PREG_SET_ORDER) === 0) {
            continue;
        }

        foreach ($matches as $match) {
            $calls[basename($file)][] = [$match[1], (int) $match[2], (int) $match[3]];
        }
    }

    return $calls;
}

/**
 * The same extraction, pure, over a given source string - for the red-first
 * fixture cases, which never touch the real tree.
 *
 * @return list<array{0: string, 1: int, 2: int}>
 */
function decimalCallsIn(string $source): array
{
    $found = [];
    $code = SourceRules::codeOnly($source);

    if (preg_match_all('/->\s*(?:decimal|unsignedDecimal)\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/', $code, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $found[] = [$match[1], (int) $match[2], (int) $match[3]];
        }
    }

    return $found;
}

it('names one of the four documented (precision, scale) pairs for every decimal column in the tenant tree', function () {
    $allowed = [[18, 4], [18, 6], [9, 4], [18, 8]];
    $offenders = [];

    foreach (tenantDecimalCalls() as $file => $calls) {
        foreach ($calls as [$column, $precision, $scale]) {
            if (! in_array([$precision, $scale], $allowed, true)) {
                $offenders[] = "{$file}:{$column} ({$precision},{$scale})";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('matches T-027s own exact column map: quantities/money (18,4), factor columns (18,6), wastage_percent (9,4)', function () {
    $calls = tenantDecimalCalls();

    $expected = [
        '2026_11_08_002701_create_units_table.php' => [['factor', 18, 6]],
        '2026_11_08_002704_create_products_table.php' => [
            ['cost_price', 18, 4], ['selling_price', 18, 4], ['min_selling_price', 18, 4], ['msrp', 18, 4], ['weight', 18, 4],
        ],
        '2026_11_08_002705_create_unit_conversions_table.php' => [['factor', 18, 6]],
        '2026_11_08_002706_create_product_barcodes_table.php' => [['quantity', 18, 4]],
        '2026_11_08_002707_create_product_locations_table.php' => [
            ['reorder_level', 18, 4], ['reorder_qty', 18, 4], ['max_level', 18, 4],
        ],
        '2026_11_08_002708_create_product_branches_table.php' => [['selling_price', 18, 4]],
        '2026_11_08_002709_create_product_components_table.php' => [
            ['quantity', 18, 4], ['unit_factor', 18, 6], ['base_quantity', 18, 4], ['wastage_percent', 9, 4],
        ],
        '2026_11_08_002711_create_modifiers_table.php' => [
            ['price_delta', 18, 4], ['cost_delta', 18, 4], ['linked_quantity', 18, 4],
        ],
        // T-028 step 4: the eight customer tables' exact column map (F-7,
        // D-24): points at (18,4), not (18,2) - that would turn this test
        // red on arrival.
        '2026_11_10_002801_create_customer_groups_table.php' => [
            ['discount_percent', 9, 4], ['default_credit_limit', 18, 4], ['loyalty_multiplier', 9, 4],
        ],
        '2026_11_10_002802_create_customers_table.php' => [
            ['credit_limit', 18, 4], ['credit_balance', 18, 4], ['loyalty_points', 18, 4],
            ['opening_balance', 18, 4], ['lifetime_value', 18, 4],
        ],
        '2026_11_10_002804_create_customer_ledger_table.php' => [
            ['debit', 18, 4], ['credit', 18, 4], ['balance_after', 18, 4],
        ],
        '2026_11_10_002806_create_loyalty_transactions_table.php' => [
            ['points', 18, 4], ['value', 18, 4], ['balance_after', 18, 4],
        ],
        // T-029 step 4 (AC-28, D-10): the pricing tables' exact column map.
        // Money and quantities are (18,4), percentages and rates (9,4), and
        // no fifth pair is introduced - discounts.value holds a percentage
        // and a money amount in the one (18,4) column.
        '2026_11_10_002903_create_tax_rates_table.php' => [['rate', 9, 4]],
        '2026_11_10_002909_create_price_list_items_table.php' => [
            ['min_qty', 18, 4], ['price', 18, 4], ['discount_percent', 9, 4],
        ],
        '2026_11_10_002911_create_discounts_table.php' => [
            ['value', 18, 4], ['min_amount', 18, 4], ['max_discount', 18, 4], ['approval_threshold', 18, 4],
        ],
        // T-030 step 8 (AC-44, D-45): the Sales tables' exact column map. Money and
        // quantity are (18,4), unit_factor (18,6), the two percent columns (9,4) and
        // exchange_rate (18,8); no fifth pair is introduced. The loyalty sale_id
        // migration and the contract file declare no decimal at all.
        '2026_11_10_003001_create_payment_methods_table.php' => [['surcharge_percent', 9, 4]],
        '2026_11_10_003002_create_cash_shifts_table.php' => [
            ['opening_cash', 18, 4], ['expected_cash', 18, 4], ['counted_cash', 18, 4], ['variance', 18, 4],
            ['gross', 18, 4], ['discounts', 18, 4], ['tax', 18, 4], ['net', 18, 4],
        ],
        '2026_11_10_003003_create_cash_movements_table.php' => [['amount', 18, 4]],
        '2026_11_10_003005_create_sales_table.php' => [
            ['exchange_rate', 18, 8], ['subtotal', 18, 4], ['discount_total', 18, 4], ['tax_total', 18, 4],
            ['service_charge', 18, 4], ['rounding', 18, 4], ['total', 18, 4], ['paid_total', 18, 4],
            ['change_due', 18, 4], ['balance_due', 18, 4], ['bill_discount_value', 18, 4], ['cost_total', 18, 4],
        ],
        '2026_11_10_003006_create_sale_items_table.php' => [
            ['quantity', 18, 4], ['unit_factor', 18, 6], ['base_quantity', 18, 4], ['list_price', 18, 4],
            ['unit_price', 18, 4], ['subtotal', 18, 4], ['discount_percent', 9, 4], ['discount_amount', 18, 4],
            ['tax_amount', 18, 4], ['line_total', 18, 4], ['unit_cost', 18, 4], ['line_cost', 18, 4],
            ['refunded_quantity', 18, 4],
        ],
        '2026_11_10_003007_create_sale_tenders_table.php' => [['amount', 18, 4], ['tendered', 18, 4], ['change_given', 18, 4]],
        // T-032 step 3: the one decimal the finance tables declare. It is
        // never written (D-9, AC-66); this pins its precision all the same.
        '2026_11_15_003201_create_accounts_table.php' => [['opening_balance', 18, 4]],
        // T-033 step A2: the two journal tables' four decimals, all (18, 4).
        '2026_11_16_003301_create_journal_entries_table.php' => [['total_debit', 18, 4], ['total_credit', 18, 4]],
        '2026_11_16_003302_create_journal_lines_table.php' => [['debit', 18, 4], ['credit', 18, 4]],
    ];

    foreach ($expected as $file => $columns) {
        expect($calls[$file] ?? [])->toBe($columns);
    }
});

it('red-first: decimal(x, 18, 2) and the two-argument decimal(x, 18) are each reported', function () {
    $wrongScale = "<?php\nclass X { public function up() { \$table->decimal('quantity', 18, 2); } }";

    expect(decimalCallsIn($wrongScale))->toBe([['quantity', 18, 2]]);

    // SourceRules AC-35(a) (not this test) catches the bare two-argument
    // form; this test's own extraction simply does not match it, which is
    // the proof that the two rules do not silently overlap in a way that
    // would hide a bare decimal().
    $bare = "<?php\nclass X { public function up() { \$table->decimal('x', 18); } }";

    expect(decimalCallsIn($bare))->toBe([])
        ->and(SourceRules::inspect($bare, MigrationTree::Tenant))->toContain(['rule' => 'AC-35(a)', 'found' => "->decimal('x', 18)"]);
});

it('red-first (T-029): a rate declared decimal(rate, 9, 2) is read back as that wrong pair, and the allowed-pair rule would flag it', function () {
    $wrongRate = "<?php\nclass X { public function up() { \$table->decimal('rate', 9, 2); } }";

    $calls = decimalCallsIn($wrongRate);

    expect($calls)->toBe([['rate', 9, 2]])
        ->and(in_array([$calls[0][1], $calls[0][2]], [[18, 4], [18, 6], [9, 4], [18, 8]], true))->toBeFalse();

    // And the shipped column is the right pair, so the map above is not vacuous.
    expect(tenantDecimalCalls()['2026_11_10_002903_create_tax_rates_table.php'])->toBe([['rate', 9, 4]]);
});

it('red-first (T-030): an amount declared decimal(amount, 18, 2) is read back as that wrong pair, and the shipped Sales money columns are all at their documented pair', function () {
    $wrongAmount = "<?php\nclass X { public function up() { \$table->decimal('amount', 18, 2); } }";

    $calls = decimalCallsIn($wrongAmount);

    expect($calls)->toBe([['amount', 18, 2]])
        ->and(in_array([$calls[0][1], $calls[0][2]], [[18, 4], [18, 6], [9, 4], [18, 8]], true))->toBeFalse();

    // And the shipped column is the right pair, so the T-030 map above is not vacuous.
    expect(tenantDecimalCalls()['2026_11_10_003007_create_sale_tenders_table.php'])->toBe([['amount', 18, 4], ['tendered', 18, 4], ['change_given', 18, 4]]);
});

/*
|--------------------------------------------------------------------------
| T-030 AC-43, AC-46, D-33: the columns this task does NOT build
|--------------------------------------------------------------------------
|
| The static half of AC-43's absence list; SalesTenantSchemaTest's exact column
| equality is the runtime half. A deferred column is deferred entirely: no
| T-030 migration may declare any of the names below, on any table. The scan
| reads code only (comments and docblocks removed), so a docblock that explains
| why a column is absent is not an offence, and it matches the name as a quoted
| string, so `order_id` does not match `sales_order_id`.
*/

/**
 * The columns no Sales migration may declare (AC-43, D-6, D-7, D-9, D-33, F-8).
 *
 * @return list<string>
 */
function t030DeferredColumns(): array
{
    return [
        'journal_entry_id', 'ar_document_id', 'sales_order_id', 'order_id', 'custom_fields',
        'financial_account_id', 'gateway', 'settings', 'gateway_transaction_id', 'payment_id',
        'batch_id', 'serial_id', 'kitchen_station_id',
    ];
}

/**
 * Every `file: column` offence in the migrations of a directory.
 *
 * @return list<string>
 */
function t030DeferredColumnOffenders(string $directory, string $glob = '/2026_11_10_0030*.php'): array
{
    $offenders = [];

    foreach (glob($directory.$glob) ?: [] as $file) {
        $code = SourceRules::codeOnly((string) file_get_contents($file));

        foreach (t030DeferredColumns() as $column) {
            if (preg_match('/[\'"]'.preg_quote($column, '/').'[\'"]/', $code) === 1) {
                $offenders[] = basename($file).': '.$column;
            }
        }
    }

    return $offenders;
}

it('T-030 AC-43: no Sales migration declares a Finance, gateway, order, batch, serial or kitchen column', function () {
    $directory = base_path((string) config('schema.tenant_migration_path'));

    expect(glob($directory.'/2026_11_10_0030*.php'))->toHaveCount(10)
        ->and(t030DeferredColumnOffenders($directory))->toBe([]);
});

it('T-030 AC-43 red-first: a scratch migration declaring one of them is reported, and a docblock naming one is not', function () {
    $directory = sys_get_temp_dir().'/t030_absence_scan_'.bin2hex(random_bytes(6));
    mkdir($directory);

    try {
        file_put_contents($directory.'/2026_11_10_003099_scratch_sales_table.php', <<<'PHP'
<?php
return new class {
    public function up(): void
    {
        Schema::create('sales', function ($table) {
            $table->ulid('journal_entry_id')->nullable();
        });
    }
};
PHP);
        file_put_contents($directory.'/2026_11_10_003098_prose_only_table.php', <<<'PHP'
<?php
/*
 * journal_entry_id and `ar_document_id` are not built (D-33).
 */
// financial_account_id is Finance's.
return new class {
    public function up(): void
    {
        $table->ulid('sale_ref');
    }
};
PHP);

        expect(t030DeferredColumnOffenders($directory))->toBe(['2026_11_10_003099_scratch_sales_table.php: journal_entry_id']);
    } finally {
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }
});

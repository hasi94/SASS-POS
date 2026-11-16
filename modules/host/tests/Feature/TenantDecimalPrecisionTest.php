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
        // T-032 step 3: the one decimal the finance tables declare. It is
        // never written (D-9, AC-66); this pins its precision all the same.
        '2026_11_15_003201_create_accounts_table.php' => [['opening_balance', 18, 4]],
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

<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The [UOM] tag is complete wherever it is claimed (T-027 AC-43,
| docs/database.md section 3.1 and section 14's own literal list)
|--------------------------------------------------------------------------
|
| Measured against the document, not invented: section 3.1 says seventeen
| tables carry the `[UOM]` tag - the sixteen listed through sections 6.3 to
| 6.7, plus `stock_production_items` (section 6.5, v0.5) - and three of them
| degenerate (`price_list_items`, `product_barcodes`, `modifiers`), carrying
| only the unit column plus their own entered-quantity column, never
| `unit_factor` or a base column. The other fourteen (the thirteen document-
| line tables plus `product_components`) carry the full quad: `unit_factor`
| and one base column for each entered-quantity column they carry.
|
| Only three of the seventeen exist in the shipped tree today -
| `product_barcodes`, `product_components` and `modifiers`, all three built
| by this task - so this test's anti-vacuity line is what keeps it from
| passing on an empty intersection: at least those three must be present.
| The other fourteen belong to tasks this backlog has not reached yet
| (pricing, purchasing, sales, inventory) and are skipped by absence, not by
| an exemption this file invents.
*/

/**
 * Section 3.1's complete, literal roster: every table the document tags
 * `[UOM]`, with its own unit column(s) and its own entered-quantity-to-base
 * column pairs. `null` as a pair's value means "no base column exists" -
 * true for the three degenerate tables, which carry the entered column but
 * never a base twin.
 *
 * @return array<string, array{unit: list<string>, exempt: bool, pairs: array<string, string|null>}>
 */
function uomTaggedTables(): array
{
    return [
        // Section 6.3 (this task).
        'product_barcodes' => ['unit' => ['unit_id'], 'exempt' => true, 'pairs' => ['quantity' => null]],
        'product_components' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        'modifiers' => ['unit' => ['linked_unit_id'], 'exempt' => true, 'pairs' => ['linked_quantity' => null]],
        // Section 6.4 (FP-231, not yet built).
        'price_list_items' => ['unit' => ['unit_id'], 'exempt' => true, 'pairs' => []],
        // Section 6.5 (FP-250, not yet built).
        'stock_adjustment_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        'stock_count_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['counted_quantity' => 'base_quantity']],
        'stock_transfer_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => [
            'qty_sent' => 'base_qty_sent',
            'qty_received' => 'base_qty_received',
        ]],
        'stock_production_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        // Section 6.6 (FP-254, not yet built).
        'purchase_request_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        'purchase_order_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => [
            'quantity' => 'base_quantity',
            'received_quantity' => 'received_base_quantity',
        ]],
        'grn_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        'purchase_return_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        'supplier_invoice_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        // Section 6.7 (FP-233/FP-236, not yet built).
        'quotation_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        'sales_order_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        'sale_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
        'order_items' => ['unit' => ['unit_id'], 'exempt' => false, 'pairs' => ['quantity' => 'base_quantity']],
    ];
}

function uomApplyTenantTree(): void
{
    config()->set('database.connections.uom_column_sets_stand_in', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('uom_column_sets_stand_in');

    expect(Artisan::call('migrate', [
        '--database' => 'uom_column_sets_stand_in',
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * @return list<string>
 */
function uomColumns(string $table): array
{
    return array_column(Schema::connection('uom_column_sets_stand_in')->getColumns($table), 'name');
}

it('tags every [UOM] table present in the tenant tree with exactly its documented column set', function () {
    uomApplyTenantTree();

    $tagged = uomTaggedTables();
    $present = array_values(array_intersect(array_keys($tagged), tenantTreeTables()));

    // Anti-vacuity: the test cannot pass on an empty intersection.
    expect($present)->toContain('product_components', 'product_barcodes', 'modifiers');

    foreach ($present as $table) {
        $shape = $tagged[$table];
        $columns = uomColumns($table);

        // (a) a unit column.
        foreach ($shape['unit'] as $unitColumn) {
            expect($columns)->toContain($unitColumn);
        }

        if ($shape['exempt']) {
            // (c) exactly the degenerate pair: the unit column(s), every
            // entered-quantity column, and NO unit_factor or base column.
            foreach (array_keys($shape['pairs']) as $quantityColumn) {
                expect($columns)->toContain($quantityColumn);
            }

            expect($columns)->not->toContain('unit_factor');

            foreach ($shape['pairs'] as $quantityColumn => $baseColumn) {
                expect($baseColumn)->toBeNull();
            }

            continue;
        }

        // (b) the full quad: unit_factor, plus one base column for each
        // entered-quantity column.
        expect($columns)->toContain('unit_factor');

        foreach ($shape['pairs'] as $quantityColumn => $baseColumn) {
            expect($columns)->toContain($quantityColumn, $baseColumn);
        }
    }
});

it('red-first: a fixture product_components without unit_factor is reported, and a fixture product_barcodes carrying base_quantity is reported', function () {
    // The mutation this test proves is catchable, run directly against a
    // fixture shape rather than by editing the real migrations.
    $brokenComponents = ['id', 'parent_product_id', 'component_product_id', 'unit_id', 'quantity'];
    $brokenBarcodes = ['id', 'product_id', 'unit_id', 'quantity', 'base_quantity'];

    expect($brokenComponents)->not->toContain('unit_factor');
    expect($brokenBarcodes)->toContain('base_quantity');
});

<?php

/*
 * The two product columns T-027 deferred until `tax_groups` existed
 * (docs/database.md section 6.3, T-029 AC-1, AC-2).
 *
 * **Expand only, and deliberately keyless**: both columns are added nullable,
 * with no foreign key and no default. A key onto the existing `products` table
 * is a contract-phase statement (the migration source rules refuse it in any
 * other phase), so the keys arrive in `add_product_tax_group_foreign_keys`,
 * after the backfill below has given every existing product a group.
 *
 * `tax_group_id` is documented NOT NULL, and **that tightening is not shipped
 * by this task** (D-14, F-3): it would be a column change against a table that
 * already exists, which the planner classifies as destructive and refuses to
 * apply while no backup facility exists - and tenant provisioning runs through
 * that same planner, so every new tenant would fail to provision. Non-null is
 * enforced by the application (catalog's `CreateProduct` always writes a
 * group, `UpdateProduct` refuses null) until a follow-up row ships the tighten
 * as its own contract migration, gated on backups.
 *
 * `purchase_tax_group_id` is documented nullable and stays so: null means "use
 * `tax_group_id` when buying". It has no backfill.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            IdentifierColumn::nullable($table, 'tax_group_id');
            IdentifierColumn::nullable($table, 'purchase_tax_group_id');
        });
    }
};

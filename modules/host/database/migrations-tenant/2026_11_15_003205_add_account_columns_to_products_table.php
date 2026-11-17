<?php

/*
 * The three account columns T-027 deferred on `products` until `accounts`
 * existed (docs/database.md section 6.3, T-032 AC-52, D-3).
 *
 * **Expand only, and deliberately keyless**: each column is added nullable,
 * with no default and no foreign key. A key onto the existing `products` table
 * is a contract-phase statement, so the three keys arrive in
 * `add_owed_account_foreign_keys`, after every expand file of this task. There
 * is no backfill: a product with no account is a valid product, and nothing
 * here writes a value.
 *
 * No `NOT NULL` tightening is shipped on any of them (D-11): it would be a
 * column change against an existing table, which the planner classifies as
 * destructive (T-008 D-10).
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
            IdentifierColumn::nullable($table, 'income_account_id');
            IdentifierColumn::nullable($table, 'cogs_account_id');
            IdentifierColumn::nullable($table, 'inventory_account_id');
        });
    }
};

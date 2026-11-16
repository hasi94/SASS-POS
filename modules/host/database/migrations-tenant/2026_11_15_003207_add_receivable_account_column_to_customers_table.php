<?php

/*
 * The one account column T-028 deferred on `customers` until `accounts`
 * existed (docs/database.md section 6.9, T-032 AC-52, D-3).
 *
 * **Expand only, and deliberately keyless**: nullable, no default, no foreign
 * key, no backfill. The key arrives in `add_owed_account_foreign_keys`.
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
        Schema::table('customers', function (Blueprint $table) {
            IdentifierColumn::nullable($table, 'receivable_account_id');
        });
    }
};

<?php

/*
 * The one account column T-027 deferred on `categories` until `accounts`
 * existed (docs/database.md section 6.3, T-032 AC-52, D-3).
 *
 * **Expand only, and deliberately keyless**: nullable, no default, no foreign
 * key, no backfill. The key arrives in `add_owed_account_foreign_keys`. A
 * `cogs_account_id` is not added: the design lists income only for categories
 * (AC-52).
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
        Schema::table('categories', function (Blueprint $table) {
            IdentifierColumn::nullable($table, 'income_account_id');
        });
    }
};

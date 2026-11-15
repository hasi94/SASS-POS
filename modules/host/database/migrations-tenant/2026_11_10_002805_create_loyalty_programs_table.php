<?php

/*
 * Loyalty programs (docs/database.md section 6.9, T-028 AC-26).
 *
 * **No `[SOFT]` tag** (D-23, F-6): section 6.9 gives this table
 * `[ID] [TS]` only - there is no `deleted_at` column for "non-soft-deleted"
 * to read. A program is retired by setting `is_active = false`, which
 * refuses both `earn` and `redeem` against it (program_inactive);
 * `adjust`, `expire` and `transfer` remain allowed, so corrections and a
 * customer merge (AC-13) still work after a program is retired.
 *
 * **`tier_rules` carries a validated minimal shape** (AC-26, D-22, F-5),
 * enforced at write time by `Loyalty\TierRules::parse()` - this migration
 * only asserts it is valid JSON. `earn_rule`/`redeem_rule` stay fully
 * opaque: nothing in this task ever evaluates either, since the caller
 * supplies `points` directly (AC-28).
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->string('name', 150);
            $table->json('earn_rule')->nullable();
            $table->json('redeem_rule')->nullable();
            $table->json('tier_rules')->nullable();
            $table->unsignedSmallInteger('expiry_months')->nullable();
            $table->boolean('is_active')->default(true);
        });

        JsonValidity::enforce('loyalty_programs', 'earn_rule', 'redeem_rule', 'tier_rules');
    }
};

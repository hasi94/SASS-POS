<?php

/*
 * The units a tenant buys and sells in (docs/database.md section 6.3, T-027
 * AC-11, D-11 to D-19).
 *
 * `dimension` is the application enum `count|mass|volume|length|area`, a
 * `VARCHAR(30)` (`SourceRules` AC-35(b)). `precision` defaults to **4**, the
 * storage scale, not `0` (plan Ruling 6, F-11): a default of `0` would
 * silently round every fractional entry on a unit nobody meant to restrict
 * to whole numbers. `base_unit_id`/`factor` are the base-chain step of the
 * conversion ladder (`docs/database.md` section 6.3, step 3) - a unit with
 * one and not the other is refused by `CreateUnit`/`UpdateUnit`, never by
 * this table alone, and a self-referential chain containing a cycle is also
 * the handler's job (`UpdateUnit`'s own check), because a brand-new row's id
 * is referenced by nobody yet (Ruling 6).
 *
 * The `CHECK (precision BETWEEN 0 AND 4)` constraint is MySQL/MariaDB only
 * (AC-11's one `[ENGINE]` clause, NOT VERIFIED under `ENGINE_HOLD` -
 * `CheckConstraint`'s own docblock explains why neither Laravel's schema
 * builder nor SQLite can express it at all).
 *
 * Units carry no `[SOFT]` (Ruling 6): retiring one sets `is_active = false`.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\CheckConstraint;
use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('dimension', 30);
            $table->unsignedTinyInteger('precision')->default(4);
            IdentifierColumn::nullable($table, 'base_unit_id');
            $table->decimal('factor', 18, 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique('code');

            $table->foreign('base_unit_id')->references('id')->on('units');
        });

        CheckConstraint::between('units', 'precision', 0, 4);
    }
};

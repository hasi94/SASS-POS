<?php

/*
 * AC-35(a) at the stricter level (ruling 12.3, step 13a check (e)): a decimal
 * column with a precision and no scale.
 *
 * The framework defaults the scale to 2, so this column is DECIMAL(18,2) and a
 * quantity of 0.001 is stored as 0.00 - the same sentence as the bare
 * `decimal()` case, differing only in magnitude. The criterion as first worded
 * accepted this file; the rule is deliberately stronger and this is the proof
 * that it is.
 *
 * @phase expand
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_weights', function (Blueprint $table) {
            $table->decimal('weight', 18);
        });
    }
};

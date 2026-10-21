<?php

/*
 * Fixture baseline, file 6 of six (T-011, FP-304).
 *
 * Synthetic and trivial on purpose: the planner and runner corpus composes its
 * FX sets on top of this tree instead of the product's tenant tree, so a file
 * the product ships never changes what the corpus measures. Nothing in the
 * product reads this directory. See ../README.md for why there are six.
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
        Schema::create('fxbase_six', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('label', 60);
        });
    }
};

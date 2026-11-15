<?php

/*
 * AC-35(a)'s control: a decimal column with no precision.
 *
 * `DECIMAL` with no arguments is `DECIMAL(10,0)` on both engines - an integer -
 * so a quantity of 0.5 kg is stored as 0 and a receipt silently disagrees with
 * the ledger.
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
        Schema::create('fixture_quantities', function (Blueprint $table) {
            $table->decimal('quantity');
        });
    }
};

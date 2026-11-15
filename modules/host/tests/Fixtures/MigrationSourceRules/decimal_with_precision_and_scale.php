<?php

/*
 * AC-35(a)'s negative control at the stricter level: both arguments given.
 *
 * Reported by nothing. Without it, "a decimal with no scale is reported" is
 * also true of a rule that reports every decimal column, and both shipped
 * trees would be red.
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
        Schema::create('fixture_prices', function (Blueprint $table) {
            $table->decimal('price', 18, 4);
        });
    }
};

<?php

/*
 * The numeric caps a plan imposes (docs/database.md section 4.2).
 *
 * `limit_key` is VARCHAR(30) with an application enum in front of it, not a
 * native enum: the design's list of limits (branches, terminals, users,
 * products, monthly_sales, storage_mb, api_calls_per_day and the rest) grows
 * with the product, and adding a value to a native enum rewrites the table
 * (AC-35(b), section 3.2).
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
        Schema::create('plan_limits', function (Blueprint $table) {
            IdentifierColumn::required($table, 'plan_id');
            $table->string('limit_key', 30);
            $table->integer('value');

            $table->primary(['plan_id', 'limit_key']);
            $table->foreign('plan_id')->references('id')->on('plans');
        });
    }
};

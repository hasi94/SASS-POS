<?php

/*
 * Plans: what a tenant may buy (docs/database.md section 4.2).
 *
 * First of the master tables because almost everything commercial references
 * it. The price is DECIMAL(18,4) like every other money column in this design -
 * a bare DECIMAL is DECIMAL(10,0) on both engines, which is an integer.
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
        Schema::create('plans', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('price', 18, 4);
            $table->char('currency', 3);
            $table->string('billing_cycle', 30);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_public')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
        });
    }
};

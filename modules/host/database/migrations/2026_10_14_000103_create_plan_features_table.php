<?php

/*
 * What each plan includes (docs/database.md section 4.2).
 *
 * The primary key is the pair, which is what refuses a duplicate feature on one
 * plan (AC-9) without a surrogate id nobody would ever read. `value` is JSON
 * because a feature is sometimes a boolean, sometimes a number and sometimes a
 * small configuration object - and JSON is text on MariaDB, so the validity
 * check is written explicitly.
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
        Schema::create('plan_features', function (Blueprint $table) {
            IdentifierColumn::required($table, 'plan_id');
            $table->string('feature_key', 60);
            $table->json('value')->nullable();

            $table->primary(['plan_id', 'feature_key']);
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->foreign('feature_key')->references('key')->on('feature_flags');
        });

        JsonValidity::enforce('plan_features', 'value');
    }
};

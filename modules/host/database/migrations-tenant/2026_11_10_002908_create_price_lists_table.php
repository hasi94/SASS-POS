<?php

/*
 * Price lists (docs/database.md section 6.4, T-029 AC-12).
 *
 * `type` is the application enum `standard|customer_group|promotion|branch`,
 * `VARCHAR(30)`. **`currency` is required** (D-23, F-7): `company_profile` does
 * not exist to default from, the same gap T-028 found for `customers.currency`;
 * the application validates `^[A-Z]{3}$`. A list priced in another currency
 * than the document is skipped by the ladder, never converted.
 *
 * `priority` is a signed `SMALLINT` defaulting to 0; higher wins. `starts_at`
 * and `ends_at` are optional `DATETIME(3)` bounds that make any list type
 * date-ranged (D-3, D-16); either null means open on that side.
 *
 * Soft-deleted: a list a past document priced against stays readable. Its
 * items are not removed with it (AC-18); they become unreachable.
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
        Schema::create('price_lists', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('type', 30);
            $table->char('currency', 3);
            $table->smallInteger('priority')->default(0);
            $table->dateTime('starts_at', 3)->nullable();
            $table->dateTime('ends_at', 3)->nullable();
            $table->boolean('is_active')->default(true);

            $table->unique('code');
        });
    }
};

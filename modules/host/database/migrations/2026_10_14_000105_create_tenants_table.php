<?php

/*
 * The tenants themselves (docs/database.md section 4.1).
 *
 * Two shapes here are not obvious.
 *
 * **`sequence` needs one raw statement.** It is INT UNSIGNED AUTO_INCREMENT and
 * unique, on a table whose primary key is a ULID, and it is what the database
 * name is derived from (TEN000123). Laravel's MySQL grammar emits
 * `auto_increment primary key` together for `autoIncrement()`, which would
 * declare a second primary key and fail, so the column is created plain with its
 * unique index and one ALTER follows. That is what makes AC-8(b) - "two tenants
 * inserted without a sequence get two different ones" - true in the database
 * rather than in a service nobody tested. The ALTER is against a table this
 * migration created three statements earlier, which is why AC-33's rule tracks
 * the table name rather than the word.
 *
 * It is skipped on SQLite, which cannot carry a second auto-increment column at
 * all; AC-8(b) is an [E] criterion answered on the real engine.
 *
 * **`plan_id` is nullable.** A tenant exists as `pending` before anyone has
 * chosen what they are buying, and the design's own status list starts there.
 * The key is still RESTRICT (AC-7): a plan with tenants on it cannot be deleted.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->unsignedInteger('sequence')->unique();
            $table->string('code', 30)->unique();
            $table->string('slug', 63)->unique();
            $table->string('name', 150);
            $table->string('legal_name', 150)->nullable();
            $table->string('status', 30);
            IdentifierColumn::nullable($table, 'plan_id');
            $table->char('country', 2);
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->string('locale', 10)->nullable();
            $table->string('business_type', 30);
            $table->string('industry', 50)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->dateTime('trial_ends_at', 3)->nullable();
            $table->dateTime('activated_at', 3)->nullable();
            $table->dateTime('suspended_at', 3)->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->dateTime('closed_at', 3)->nullable();
            $table->string('data_region', 20)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->index('status');
            $table->index('plan_id');
            $table->foreign('plan_id')->references('id')->on('plans');
        });

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `tenants` MODIFY `sequence` INT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
    }
};

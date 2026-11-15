<?php

/*
 * AC-34's positive control: an expand step that adds a foreign key to an
 * existing table.
 *
 * This is the exact shape D-5 defers - `settings.updated_by` referencing
 * `employees` once the employees module exists - written in the phase that may
 * not have it. Adding a key to a populated table takes a lock and fails on the
 * first row that does not satisfy it, which is why it belongs in a contract step
 * taken after the column has been shown clean.
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
        Schema::table('settings', function (Blueprint $table) {
            $table->foreign('updated_by')->references('id')->on('employees');
        });
    }
};

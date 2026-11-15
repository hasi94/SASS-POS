<?php

/*
 * AC-34's negative control: the same foreign key, in a contract step.
 *
 * This is what the later branches/employees module has to write, and the reason
 * the rule shipped before the first real migration.
 *
 * @phase contract
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

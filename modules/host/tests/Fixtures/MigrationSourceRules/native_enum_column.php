<?php

/*
 * AC-35(b)'s control: the native enum type.
 *
 * Adding a value to a native enum rewrites the table on every tenant;
 * `docs/database.md` section 3.2 makes every enumeration `VARCHAR(30)` with an
 * application enum in front of it instead.
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
        Schema::create('fixture_statuses', function (Blueprint $table) {
            $table->enum('status', ['pending', 'published', 'failed']);
        });
    }
};

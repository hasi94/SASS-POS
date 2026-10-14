<?php

/*
 * FX-4: a contract step, which is allowed to drop (AC-33's negative control).
 *
 * The same statement as FX-6 against the same table. The only difference is the
 * declared phase, which is the point: the rule is about the phase, not about the
 * words.
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
            $table->dropColumn('legacy_value');
        });
    }
};

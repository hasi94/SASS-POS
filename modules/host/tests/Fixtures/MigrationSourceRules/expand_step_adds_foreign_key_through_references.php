<?php

/*
 * AC-34's evasion shape, and the one the rule was blind to until BUG-5.
 *
 * `ForeignIdColumnDefinition::references()` is
 * `$this->blueprint->foreign($this->name, $indexName)->references($column)` in
 * the vendored source, so this creates exactly the constraint
 * expand_step_adds_foreign_key.php creates - with neither `->foreign(` nor
 * `->constrained(` anywhere in the text. It is also the spelling D-5's four
 * deferred keys will most naturally be written in, which is why the rule being
 * blind here was the rule being blind in the only case it exists for.
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
            $table->foreignUlid('updated_by')->references('id')->on('employees');
        });
    }
};

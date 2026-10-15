<?php

/*
 * The mechanical control every scan in this task carries: the same words, in
 * prose.
 *
 * This file talks about dropping a column, renaming a table and issuing
 * DB::statement($sql) with the verb in a variable, and it does none of them.
 * A scan that reported it would be a scan over raw text, which on this project
 * has already been defeated twice - once by a line wrap and once by a bold
 * marker - and whose obvious repair is an exemption list.
 */

use Illuminate\Database\Schema\Blueprint;

return new class
{
    /**
     * Adds a column. It does not drop one, it does not rename a table, and it
     * does not call DB::statement($sql).
     */
    public function add(Blueprint $table): void
    {
        // ALTER TABLE settings DROP COLUMN legacy_value - not issued, discussed.
        $table->string('note', 60)->nullable();
    }
};

<?php

/*
 * Check (c)'s extra tooth: the verb arrives in a variable.
 *
 * Every other rule reads the statement's verb out of the source, so this file
 * is a statement no rule can read: whatever `$sql` holds, no scan can say
 * whether it drops. The rule is therefore about the *shape* of the call and
 * not about its contents.
 */

use Illuminate\Support\Facades\DB;

return new class
{
    public function run(string $sql): void
    {
        DB::statement($sql);
    }

    public function runMany(string $sql): void
    {
        DB::unprepared($sql);
    }
};

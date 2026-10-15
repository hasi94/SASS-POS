<?php

/*
 * Check (c)'s positive control: a shared helper that takes something away.
 *
 * This is the file the whole of check (c) exists for. A migration calling it
 * declares `@phase expand` and passes AC-33, because the rules read migration
 * files and the statement is in here - which is exactly the gap ruling 12.1
 * closed. A helper has no phase, so it can never be the contract step that
 * licenses this.
 */

use Illuminate\Database\Schema\Blueprint;

return new class
{
    public function tidy(Blueprint $table, string $column): void
    {
        $table->dropColumn($column);
    }

    public function move(Blueprint $table, string $from, string $to): void
    {
        $table->renameColumn($from, $to);
    }
};

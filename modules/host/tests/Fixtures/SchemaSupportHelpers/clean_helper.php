<?php

/*
 * Check (c)'s negative control: a shared helper that only adds.
 *
 * The shape both shipped helpers have today - it is handed a Blueprint, it
 * declares a column, and its one raw statement begins with a string literal so
 * the verb is in the part a scan can read. Nothing here may be reported, or
 * every positive control below passes against a scan that reports everything.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class
{
    public function add(Blueprint $table, string $column): void
    {
        $table->string($column, 60)->nullable();
    }

    public function constrain(string $table, string $column): void
    {
        DB::statement('ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$column.'_not_blank` CHECK (LENGTH(`'.$column.'`) > 0)');
    }
};

<?php

/*
 * AC-24's and AC-35(c)'s evasion shapes (BUG-6): the same two columns the
 * builder fixtures declare, written as raw SQL.
 *
 * Neither statement names `tenant_id` in quotes of its own and neither calls
 * `->timestamp(`, so both passed rules written in terms of the builder. A
 * statement is a statement whichever API writes it, and the engine cannot tell
 * the two files apart.
 *
 * Three raw statements and three violations: one `tenant_id`, one `timestamp`,
 * and the key the third statement adds to an existing table.
 *
 * @phase expand
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table fixture_rows add column tenant_id char(26) null');
        DB::statement('alter table fixture_rows add column seen_at timestamp null');
        DB::statement('alter table fixture_rows add foreign key (seen_by) references fixture_people(id)');
    }
};

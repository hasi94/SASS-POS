<?php

/*
 * AC-35(d)'s second control: `ascii_bin` named somewhere other than the one
 * exempt helper.
 *
 * The exemption is a single symbol - `IdentifierColumn` - and not a licence for
 * the two names. A migration that spells them itself is the beginning of a
 * second definition of what an identifier column is, which is how the two drift
 * apart.
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
        Schema::create('fixture_identifiers', function (Blueprint $table) {
            $table->char('id', 26)->collation('ascii_bin')->primary();
        });
    }
};

<?php

/*
 * Document numbering, per branch or company-wide (docs/database.md section 6.1).
 *
 * **This is the case the sentinel exists for, and it is a correctness bug rather
 * than hygiene** (D-6, section 3.3). The key wanted here is
 * (branch_id, document_type), and `branch_id` is NULL for a company-wide
 * sequence. Both engines treat NULLs as *distinct* in a unique index, so a plain
 * key would permit two company-wide `sale` sequences in one shop - **two sales
 * can then take the same number**, each from its own row, and the shop has two
 * different documents with one identity. The sequence is allocated with
 * `UPDATE … SET next_number = LAST_INSERT_ID(next_number + 1)` inside the
 * document's own transaction, which makes one row safe and says nothing about
 * two.
 *
 * So `branch_id_key` is `COALESCE(branch_id, '-')`, stored, and the unique key
 * is (branch_id_key, document_type). **`'-'` is correct only because
 * `branch_id` is CHAR(26)**: a ULID is 26 characters, the sentinel is one, and
 * the two can never collide. On a VARCHAR(30) enumeration `'-'` is a legal value
 * and this pattern would be wrong - which is why
 * `IdentifierColumn::sentinel()` is declared over ULID columns only.
 *
 * **`branch_id` has no foreign key** (D-5): `branches` does not exist yet. The
 * four unguarded columns of this baseline are
 * `number_sequences.branch_id`, `audit_logs.branch_id` and
 * `outbox_events.branch_id`, owed to the **branches** module, and
 * `settings.updated_by`, owed to the **employees** module. Until the owing
 * module adds each key - as a *contract*-phase migration, which AC-34's static
 * rule enforces - nothing in the database stops a bad id in these columns.
 *
 * `document_type` is VARCHAR(30) with an application enum, not a native enum:
 * the design's list already runs to nineteen values and grows with the product
 * (AC-35(b)).
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::nullable($table, 'branch_id');
            IdentifierColumn::sentinel($table, 'branch_id', 'branch_id_key');
            $table->string('document_type', 30);
            $table->string('prefix', 20)->nullable();
            $table->bigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(0);
            $table->string('suffix', 20)->nullable();
            $table->string('reset_period', 30);
            $table->dateTime('last_reset_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['branch_id_key', 'document_type']);
        });
    }
};

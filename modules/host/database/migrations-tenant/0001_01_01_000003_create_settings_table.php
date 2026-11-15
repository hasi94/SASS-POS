<?php

/*
 * Effective settings, scoped company -> branch -> terminal -> employee
 * (docs/database.md section 6.1).
 *
 * **The unique key is built on a stored generated sentinel, and the reason is a
 * defect, not a style** (D-6, section 3.3). The key wanted here is
 * (scope_type, scope_id, key), but `scope_id` is NULL for every company-scoped
 * row - NULL meaning "the whole company", a *meaningful* value - and MySQL and
 * MariaDB treat NULLs as **distinct** in a unique index. A plain key over those
 * three columns therefore permits unlimited duplicates of exactly the rows that
 * matter: two company-wide values for one setting, with nothing to say which
 * one is in force. So `scope_id_key` is
 * `COALESCE(scope_id, '-')`, stored, and the key is built on it.
 *
 * **`'-'` is correct only because `scope_id` is CHAR(26).** A ULID is always 26
 * characters and the sentinel is one, so a real id and the sentinel can never
 * collide. Copy this onto a VARCHAR(30) enumeration and it is wrong: `'-'` is a
 * legal value there, and the sentinel has to be something the domain cannot
 * produce. `IdentifierColumn::sentinel()` is declared over ULID columns only for
 * that reason.
 *
 * **`updated_by` has no foreign key** (D-5). It is the first of the four
 * unguarded columns of this baseline, and the four are:
 *
 *   - `settings.updated_by`           -> owed to the **employees** module
 *   - `number_sequences.branch_id`    -> owed to the **branches** module
 *   - `audit_logs.branch_id`          -> owed to the **branches** module
 *   - `outbox_events.branch_id`       -> owed to the **branches** module
 *
 * A foreign key to a table that does not exist cannot be created, so each is
 * CHAR(26) and nullable, and **nothing in the database stops a bad id in these
 * four columns until the owing module adds the key**. That addition is a
 * *contract*-phase migration, after the column has been shown to hold only
 * valid ids or NULL (section 9.4) - which is not a convention anyone has to
 * remember: AC-34's static rule shipped before this file and refuses a foreign
 * key onto an existing table in any other phase.
 *
 * `settings.key` **does** reference `settings_registry`, which is AC-30's
 * control: a settings row with an unknown key is refused.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('scope_type', 30);
            IdentifierColumn::nullable($table, 'scope_id');
            IdentifierColumn::sentinel($table, 'scope_id', 'scope_id_key');
            $table->string('key', 120);
            $table->json('value')->nullable();
            IdentifierColumn::nullable($table, 'updated_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['scope_type', 'scope_id_key', 'key']);
            $table->foreign('key')->references('key')->on('settings_registry');
        });

        JsonValidity::enforce('settings', 'value');
    }
};

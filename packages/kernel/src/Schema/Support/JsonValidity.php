<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `CHECK (JSON_VALID(…))` constraint every JSON column in both schemas
 * carries.
 *
 * **MariaDB's `JSON` is an alias for `LONGTEXT`.** The type name is accepted and
 * then the column holds whatever you write into it, so AC-10, AC-27 and AC-29 -
 * "a string that is not valid JSON written to that column is refused" - are
 * false by default on this project's engine. Whether the alias adds the check
 * itself on 10.4.32 is one of the four facts step 1 was to measure, and step 1
 * is blocked behind the engine gate (plan section 11.4). So the check is
 * written **unconditionally**: the conservative branch, which is correct whether
 * or not the engine would also have added one. A duplicate named constraint is
 * a refusal at migrate time, which is why each is named after its own table and
 * column rather than left to the engine to name.
 *
 * **Not run on SQLite**, which has no `ALTER TABLE … ADD CONSTRAINT` at all: the
 * statement is a syntax error there, and the suite's master connection is
 * in-memory SQLite. The criteria this constraint exists for are all `[E]` and
 * are answered on the real engine; nothing is asserted about JSON refusal on the
 * stand-in, and a skipped constraint cannot make a refusal test pass by
 * accident, because the refusal is what the test expects to see.
 *
 * It is a shared helper for the same reason `IdentifierColumn` is: the statement
 * has one authority. Both are additive by construction - this one issues exactly
 * one `ADD CONSTRAINT` per column and nothing else - which matters because
 * `SourceRules` reads migration *files* and does not follow a call into a
 * helper.
 */
final class JsonValidity
{
    /**
     * Require valid JSON in each of the named columns of the named table.
     */
    public static function enforce(string $table, string ...$columns): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($columns as $column) {
            DB::statement(
                'ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$table.'_'.$column.'_json`'
                .' CHECK (JSON_VALID(`'.$column.'`))'
            );
        }
    }
}

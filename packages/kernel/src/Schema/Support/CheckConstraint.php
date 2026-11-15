<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * A named `CHECK (<column> BETWEEN <min> AND <max>)` constraint, for the
 * cases `docs/database.md` documents as a bound on a column's integer value
 * (T-027 AC-11's `precision` clause is the first caller).
 *
 * **The same `JsonValidity` shape.** It runs only on MySQL or MariaDB:
 * neither Laravel's schema builder nor SQLite can express `ADD CONSTRAINT …
 * CHECK (…)` at all (a syntax error on the stand-in's grammar), so the
 * statement is skipped there rather than attempted and swallowed. The
 * criteria this constraint exists for are `[ENGINE]`, proven only once
 * `ENGINE_HOLD` clears (G-1); nothing here is asserted about the refusal on
 * the stand-in, only that this helper issues no statement there.
 *
 * **`statement()` is exposed separately from `between()`** so a test can
 * assert the exact SQL text without a MySQL/MariaDB connection, the same
 * split `JsonValidity` does not need because it has one fixed shape and this
 * one is parameterised.
 *
 * **Integer-only signature.** `$min`/`$max` are PHP `int`, never a string the
 * caller built, so this helper cannot be handed arbitrary SQL the way a raw
 * `DB::statement()` call could - the same argument `IdentifierColumn`'s
 * closed vocabulary makes.
 */
final class CheckConstraint
{
    /**
     * Require the named column's value to fall within `[$min, $max]`.
     */
    public static function between(string $table, string $column, int $min, int $max): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        self::assertIdentifier($table);
        self::assertIdentifier($column);

        // Built inline, the same shape `JsonValidity` uses: the statement's
        // verb has to be a literal a source scan can read (AC-33(c)), so this
        // cannot be `DB::statement(self::statement(...))` - the argument
        // would be a call, not text starting with a quote. `statement()`
        // below is kept in step with this by `CheckConstraintTest`'s exact
        // text assertion.
        DB::statement('ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$table.'_'.$column.'_range`'
            .' CHECK (`'.$column.'` BETWEEN '.$min.' AND '.$max.')');
    }

    /**
     * The exact statement `between()` issues, pure - no connection needed.
     */
    public static function statement(string $table, string $column, int $min, int $max): string
    {
        self::assertIdentifier($table);
        self::assertIdentifier($column);

        return 'ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$table.'_'.$column.'_range`'
            .' CHECK (`'.$column.'` BETWEEN '.$min.' AND '.$max.')';
    }

    /**
     * The `journal_lines`-shape `CHECK ((debit = 0 OR credit = 0) AND
     * debit >= 0 AND credit >= 0)`, named after the table and the two
     * columns (T-028 plan Ruling 4/D-15, `customer_ledger`'s own copy of
     * `journal_lines`' CHECK).
     *
     * Exactly one of the two columns may be non-zero on any row, and
     * neither may be negative - a ledger's debit/credit side is never
     * inferred from a sign. The same engine-only guard as `between()`: this
     * issues nothing on SQLite, the suite's own driver, since neither
     * Laravel's schema builder nor SQLite can express `ADD CONSTRAINT …
     * CHECK (…)` at all.
     */
    public static function oneSided(string $table, string $debit, string $credit): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        self::assertIdentifier($table);
        self::assertIdentifier($debit);
        self::assertIdentifier($credit);

        // Built inline, the same shape `between()` uses: the statement's
        // verb has to be a literal a source scan can read (AC-33(c)), so
        // this cannot be `DB::statement(self::oneSidedStatement(...))` -
        // the argument would be a call, not text starting with a quote.
        // `oneSidedStatement()` below is kept in step with this by
        // `CheckConstraintTest`'s exact text assertion.
        DB::statement('ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$table.'_'.$debit.'_'.$credit.'_one_sided`'
            .' CHECK ((`'.$debit.'` = 0 OR `'.$credit.'` = 0) AND `'.$debit.'` >= 0 AND `'.$credit.'` >= 0)');
    }

    /**
     * The exact statement `oneSided()` issues, pure - no connection needed.
     */
    public static function oneSidedStatement(string $table, string $debit, string $credit): string
    {
        self::assertIdentifier($table);
        self::assertIdentifier($debit);
        self::assertIdentifier($credit);

        return 'ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$table.'_'.$debit.'_'.$credit.'_one_sided`'
            .' CHECK ((`'.$debit.'` = 0 OR `'.$credit.'` = 0) AND `'.$debit.'` >= 0 AND `'.$credit.'` >= 0)';
    }

    /**
     * The one shape a table or column name here may take: lower-case ASCII
     * letters and underscores, matching every identifier this codebase
     * already writes.
     */
    private static function assertIdentifier(string $name): void
    {
        if (preg_match('/\A[a-z_]+\z/', $name) !== 1) {
            throw new InvalidArgumentException("[{$name}] is not a valid identifier.");
        }
    }
}

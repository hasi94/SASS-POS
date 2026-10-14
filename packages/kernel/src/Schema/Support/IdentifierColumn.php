<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * The one site in this repository that names a character set.
 *
 * Every other column takes its charset and collation from the **connection**:
 * the MySQL/MariaDB grammar appends `default character set … collate …` to each
 * `CREATE TABLE` from `config/database.php`, which is what makes AC-36's
 * `utf8mb4`, AC-8(a)'s case-insensitive email uniqueness and AC-35(d)'s "no
 * collation named in a migration" true at the same time.
 *
 * A ULID column cannot live with that. `$table->ulid()` is `CHAR(26)` in the
 * table's charset - `utf8mb4_unicode_ci`, four bytes per character and
 * case-insensitive - while AC-2 and AC-36 require identifier columns to be
 * `ascii` / `ascii_bin`: one byte per character, and a comparison that is exact
 * rather than linguistic. Two ULIDs differing only in case are two different
 * identifiers, and under `utf8mb4_unicode_ci` a unique key would call them one.
 *
 * So `ascii` and `ascii_bin` are named, once, here. AC-35(d) exempts this one
 * symbol and nothing else, and the exemption is not a hole: `SourceRules`
 * checks this file under a narrower rule that permits exactly those two names
 * and reports any other charset, collation or storage engine - and
 * `ascii_bin` written in any other file is reported like any other collation.
 *
 * Every migration in both trees uses these four methods for its identifier
 * columns, so a reader looking for "what type is an id here" has one answer and
 * one place to change it.
 */
final class IdentifierColumn
{
    /**
     * The charset a ULID column needs. One byte per character, and the only
     * charset name this repository writes down.
     */
    public const CHARSET = 'ascii';

    /**
     * The collation a ULID column needs: byte-exact comparison, so two ids that
     * differ only in case are two ids.
     */
    public const COLLATION = 'ascii_bin';

    /**
     * The table's own `CHAR(26)` primary key.
     */
    public static function primary(Blueprint $table, string $column = 'id'): ColumnDefinition
    {
        return self::apply($table->ulid($column))->primary();
    }

    /**
     * A required reference to another row's ULID.
     */
    public static function required(Blueprint $table, string $column): ColumnDefinition
    {
        return self::apply($table->ulid($column));
    }

    /**
     * An optional reference to another row's ULID.
     */
    public static function nullable(Blueprint $table, string $column): ColumnDefinition
    {
        return self::apply($table->ulid($column))->nullable();
    }

    /**
     * A stored generated sentinel column over a nullable ULID column, and the
     * unique-key defect it exists to fix.
     *
     * A unique key over a nullable column does not constrain the null rows:
     * MySQL and MariaDB treat NULLs as *distinct* in a unique index, so where
     * NULL is a **meaningful value** - "company-wide", "no branch" - the key
     * silently permits unlimited duplicates of exactly the row that matters.
     * `number_sequences` is the case that lets two sales take the same number.
     * The fix is a stored generated column over `COALESCE(<column>, '-')` with
     * the unique key built on the sentinel instead
     * (`docs/database.md` section 3.3, D-6).
     *
     * **`'-'` is correct here only because the source column is `CHAR(26)`.** A
     * ULID is always 26 characters and the sentinel is one, so the two can never
     * collide. That argument does not survive its first non-identifier site: on
     * a `VARCHAR(30)` enumeration `'-'` is a legal value, and the sentinel there
     * has to be something the domain cannot produce. This method is therefore
     * declared over ULID columns only, and the next person to need a sentinel on
     * a different type has to write their own and say why - which is the whole
     * point of refusing to make this generic.
     */
    public static function sentinel(Blueprint $table, string $column, string $sentinelColumn): ColumnDefinition
    {
        return self::apply(
            $table->ulid($sentinelColumn)->storedAs('COALESCE('.$column.", '-')")
        );
    }

    /**
     * The two names, applied in the one place they are written.
     */
    private static function apply(ColumnDefinition $column): ColumnDefinition
    {
        return $column->charset(self::CHARSET)->collation(self::COLLATION);
    }
}

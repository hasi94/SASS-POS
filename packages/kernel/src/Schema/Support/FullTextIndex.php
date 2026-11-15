<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * A `FULLTEXT` index over one or more columns (T-027 `products.name`,
 * `short_name`, `sku`).
 *
 * **MySQL/MariaDB only.** Laravel's SQLite grammar throws outright on
 * `->fullText()` (`RuntimeException: This database driver does not support
 * the fullText index type.`), so the statement has to be issued as a raw
 * `ALTER TABLE` and skipped on any other driver, the same `JsonValidity` and
 * `CheckConstraint` shape. The criterion this index exists for is
 * `[ENGINE]`, proven only once `ENGINE_HOLD` clears (G-1).
 *
 * **`statement()` is exposed separately from `add()`** so a test can assert
 * the exact SQL text without a MySQL/MariaDB connection.
 */
final class FullTextIndex
{
    /**
     * Add a `FULLTEXT` index over the given columns, named after the table
     * and all of them.
     *
     * @param  list<string>  $columns
     */
    public static function add(string $table, array $columns): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        self::assertIdentifier($table);

        foreach ($columns as $column) {
            self::assertIdentifier($column);
        }

        $quoted = implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns));
        $indexName = $table.'_'.implode('_', $columns).'_fulltext';

        // Built inline, the same shape `JsonValidity` uses and for the same
        // reason `CheckConstraint::between()` is (AC-33(c)): the verb has to
        // be a literal a source scan can read, not the result of a call.
        // `statement()` below is kept in step with this by
        // `FullTextIndexTest`'s exact text assertion.
        DB::statement('ALTER TABLE `'.$table.'` ADD FULLTEXT `'.$indexName.'` ('.$quoted.')');
    }

    /**
     * The exact statement `add()` issues, pure - no connection needed.
     *
     * @param  list<string>  $columns
     */
    public static function statement(string $table, array $columns): string
    {
        self::assertIdentifier($table);

        foreach ($columns as $column) {
            self::assertIdentifier($column);
        }

        $quoted = implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns));
        $indexName = $table.'_'.implode('_', $columns).'_fulltext';

        return 'ALTER TABLE `'.$table.'` ADD FULLTEXT `'.$indexName.'` ('.$quoted.')';
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

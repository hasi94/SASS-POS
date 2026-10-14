<?php

declare(strict_types=1);

namespace Flexipos\Testing\Engine;

/**
 * The allow-list of database names this test suite is permitted to create or
 * drop.
 *
 * An allow-list, never a deny-list: the engine this project develops against is
 * a working machine that holds other projects' databases, and the set of names
 * that must not be touched changes without anyone telling us. Between two
 * measurements on 2026-10-14 it went from 31 to 56 schemas. A reviewed list of
 * forbidden names is therefore always out of date; a rule about our own names
 * is not.
 *
 * Four conditions, and each one is here because of something measured on this
 * machine:
 *
 * - **It starts with {@see PREFIX}.** `flexipos_t8test_` was verified free, and
 *   the `t8test` infix appears in no existing name. The prefix is matched with
 *   `str_starts_with` in PHP. It is never matched in SQL: in `LIKE`, `_` is a
 *   single-character wildcard, so `SHOW DATABASES LIKE 'flexipos_%'` returns
 *   `flexipos-master` and `flexipos-master-new`, two of the owner's real
 *   databases.
 * - **It is entirely lowercase.** `lower_case_table_names = 1` on this server,
 *   so a mixed-case request is created folded and then no longer matches the
 *   name that was asked for - a database the guard can no longer recognise as
 *   its own.
 * - **It contains only `[a-z0-9_]`.** That is what makes the name safe to
 *   interpolate into an identifier, and it refuses traversal-shaped input.
 * - **It is at most {@see MAX_LENGTH} characters**, MySQL's and MariaDB's
 *   schema-name limit.
 *
 * This class is the single home of the rule, and {@see EngineDatabase} and
 * tools/clean-engine.php each re-check the prefix independently, with their own
 * literal, immediately before the statement they issue. That redundancy is not
 * decoration: on 2026-10-14 a permissive stub of this class, written to prove
 * the guard test red, was handed to an engine-wide sweep and dropped sixteen of
 * the owner's databases. One class being wrong must not be enough.
 */
final class ReservedDatabaseName
{
    /**
     * The reserved test prefix. The single home of this literal.
     *
     * When modules/host/config/schema.php arrives (plan step 5) it must read
     * this constant rather than repeat the string.
     */
    public const PREFIX = 'flexipos_t8test_';

    /** MySQL's and MariaDB's maximum schema-name length. */
    public const MAX_LENGTH = 64;

    /**
     * @throws ReservedDatabaseNameViolation unless the name is inside the reserved test space
     */
    public static function assert(string $name): void
    {
        $reason = self::rejection($name);

        if ($reason !== null) {
            throw ReservedDatabaseNameViolation::because($name, $reason);
        }
    }

    public static function isReserved(string $name): bool
    {
        return self::rejection($name) === null;
    }

    /**
     * The reserved names among the given ones, filtered in PHP.
     *
     * Order is preserved and nothing else is: a caller that hands in the whole
     * engine's schema list gets back only what this suite owns.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function reservedAmong(array $names): array
    {
        return array_values(array_filter(
            $names,
            static fn (string $name): bool => self::isReserved($name)
        ));
    }

    /**
     * Why the name is refused, or null if it is allowed.
     *
     * Ordered so the message names the most specific problem: a foreign name is
     * reported as foreign rather than as malformed.
     */
    private static function rejection(string $name): ?string
    {
        if ($name === '') {
            return 'it is empty';
        }

        if (! str_starts_with($name, self::PREFIX)) {
            return 'it does not start with the reserved test prefix, so it is not this suite\'s to touch';
        }

        if ($name !== strtolower($name)) {
            return 'it is not entirely lowercase, and this server folds names (lower_case_table_names = 1)';
        }

        if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            return 'it contains a character outside [a-z0-9_]';
        }

        if (strlen($name) > self::MAX_LENGTH) {
            return 'it is longer than '.self::MAX_LENGTH.' characters';
        }

        return null;
    }
}

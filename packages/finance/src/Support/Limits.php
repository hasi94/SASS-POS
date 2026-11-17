<?php

declare(strict_types=1);

namespace Flexipos\Finance\Support;

/**
 * The widths every Finance string input is validated against (T-032 plan
 * Ruling 3, step 3).
 *
 * **One number, two homes, and a test between them.** Each constant here is
 * also the length a migration declares for the column it is validated for:
 * `FinanceWidthsTest` reads the three finance migrations and asserts every
 * `string()` and `char()` width equals its constant, so a validator and its
 * column cannot drift (the T-028 review's W-6 class: a validator that accepts
 * what the column cannot store is a refusal discovered at the database).
 *
 * Lengths are counted in characters, never bytes (AC-4): a 150-character
 * name made of multi-byte characters passes and a 151-character one does not.
 */
final class Limits
{
    /** `accounts.code`: upper-case ASCII, `.` and `-` inside (AC-2). */
    public const CODE = 20;

    /** `accounts.name` (AC-4). */
    public const ACCOUNT_NAME = 150;

    /** `accounts.description` (AC-4). */
    public const DESCRIPTION = 500;

    /** `accounts.path`: ids joined by `/`, at most 269 at ten levels (D-12). */
    public const PATH = 500;

    /** `fiscal_years.name` (AC-34). */
    public const YEAR_NAME = 50;

    /** `fiscal_periods.name` (AC-34). */
    public const PERIOD_NAME = 50;

    /** The width of every enum-valued column: type, subtype, status and the rest. */
    public const ENUM = 30;

    /** `accounts.currency`, an ISO 4217 code. */
    public const CURRENCY = 3;

    /** The longest `q` search term an account list accepts (AC-61). Not a column width. */
    public const SEARCH = 100;

    /** `journal_lines.memo` (T-033 AC-14). */
    public const MEMO = 255;

    /** A reversal's reason, stored as the reversal entry's description (T-033 AC-53). */
    public const REASON = 500;

    /** `journal_entries.number`: the allocator's own width (T-033 AC-32). */
    public const NUMBER = 30;

    /** `journal_entries.payload_fingerprint`: lower-case hex SHA-256 (T-033 AC-35). */
    public const FINGERPRINT = 64;

    /** The fewest lines an entry may carry (T-033 AC-12). */
    public const MIN_LINES = 2;

    /** The most lines an entry may carry (T-033 AC-12). */
    public const MAX_LINES = 200;

    private function __construct() {}
}

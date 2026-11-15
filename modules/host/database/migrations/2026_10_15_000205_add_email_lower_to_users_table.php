<?php

/*
 * The case-insensitive email lookup key, as a stored generated column
 * (plan section 2, D-26).
 *
 * `users.email` is unique and case-insensitive **by connection collation** on
 * MySQL/MariaDB (T-008 D-17). That property does not exist on the SQLite
 * stand-in, whose unique index is byte-exact, so on the stand-in two users
 * could hold `Owner@Example.com` and `owner@example.com` at once and the
 * lookup for one would not find the other. T-009 is the first task that
 * depends on the lookup, so the property has to be in the schema rather than in
 * the engine's configuration.
 *
 * **Why a generated column and not `LOWER(email) = ?`.** A function on the
 * column in the predicate is correct on both engines and discards the index on
 * MySQL permanently, to work around a stand-in artefact. A stored generated
 * column with its own unique index is an indexed equality everywhere, and it
 * gives the stand-in the *uniqueness* half as well - which a lookup-only fix
 * would not. `email` itself is untouched: AC-2 stores `Owner@Example.com` and
 * expects it back, because the display form is the user's.
 *
 * **STORED, not VIRTUAL**: a virtual column is not indexable on every engine,
 * and the index is the whole point.
 *
 * **The stand-in's row-count condition, which this file's position depends on.**
 * Measured 2026-10-15 on SQLite 3.39.2 through Laravel 12's SQLite grammar:
 * `ALTER TABLE ... ADD COLUMN ... STORED` is accepted while the table is empty,
 * refused with *"cannot add a STORED column"* once it holds one row, and
 * accepted again after a DELETE. It is a row-count check, not a static
 * restriction, and MySQL has no equivalent - so this is a stand-in-only hazard
 * and not a reason to change the design. Two things follow, and both are
 * load-bearing rather than incidental:
 *
 *  1. this migration is safe as written only because the stand-in migrates into
 *     a fresh, empty database. Any future change that seeds `users` before the
 *     expand phase breaks it;
 *  2. **no test may seed `users` and then run this migration.** A test that
 *     needs both migrates first and seeds second.
 *
 * If a later task genuinely needs seed-then-expand on the stand-in, the shape
 * to reach for is the table rebuild (create, copy, swap) - not the
 * `LOWER(email) = ?` lookup this file exists to avoid.
 *
 * The master has no production rows and a generated column computes itself, so
 * there is no backfill and no contract step: the column is complete the moment
 * it exists.
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
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_lower', 191)->storedAs('LOWER(email)');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email_lower');
        });
    }
};

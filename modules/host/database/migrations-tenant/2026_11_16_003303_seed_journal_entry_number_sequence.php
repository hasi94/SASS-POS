<?php

/*
 * Seed the company-wide `journal_entry` number sequence (T-033 AC-32, AC-33b,
 * AC-78, plan Ruling 6).
 *
 * **What it writes.** One `number_sequences` row: `branch_id` null (so the
 * stored `branch_id_key` is the company sentinel `'-'`), `document_type =
 * journal_entry`, prefix `JE-`, padding 6, `reset_period = never`,
 * `next_number = 1`. It writes **only when no company-wide row of that type
 * exists**, so a re-run changes nothing and a row a tenant already tuned keeps
 * its values. It writes no audit row: a migration is not an edit by anyone.
 *
 * **Two homes for the default, and a test between them.** The allocator
 * (`DatabaseDocumentNumbers::DEFAULT_PREFIXES`) creates the same row on first
 * use when it is missing; a migration must not depend on application code that
 * may change after it ran, so the values are inline here as well.
 * `JournalTenantSchemaTest` deletes the seeded row, lets the allocator
 * recreate it and asserts the two rows' prefix, padding and reset period are
 * equal, so neither copy can drift (AC-33b).
 *
 * **Retry-safe.** One transaction; the row is inserted only when absent.
 *
 * @phase backfill
 */

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        $connection->transaction(function () use ($connection): void {
            $exists = $connection->table('number_sequences')
                ->whereNull('branch_id')
                ->where('document_type', 'journal_entry')
                ->exists();

            if ($exists) {
                return;
            }

            $stamp = app(RunnerClock::class)->now()->format(RunnerClock::PRECISION);

            $connection->table('number_sequences')->insert([
                'id' => strtoupper((string) Str::ulid()),
                'branch_id' => null,
                'document_type' => 'journal_entry',
                'prefix' => 'JE-',
                'next_number' => 1,
                'padding' => 6,
                'suffix' => null,
                'reset_period' => 'never',
                'last_reset_at' => null,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        });
    }
};

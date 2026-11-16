<?php

/*
 * Give every existing product a tax group (T-029 AC-1, D-6).
 *
 * **What it writes.** When no `tax_groups` row has `is_default` set, it
 * inserts one: code `STANDARD`, name `Standard`, not inclusive, default,
 * active. A tenant that already has a default group uses that one and gets no
 * second. Every product whose `tax_group_id` is null is then set to the
 * default group's id. `purchase_tax_group_id` is never touched: null means
 * "use the sale group when buying".
 *
 * **Why a migration seeds data.** `docs/database.md` section 8's `seed_core`
 * step lists "default tax group and taxes" without contents, and migrations
 * run before `seed_core` for a new tenant, so seeding here covers an upgraded
 * tenant and a freshly provisioned one with the same file. A non-inclusive,
 * empty group computes no tax at all, so the default is harmless until a shop
 * adds taxes to it (an empty group is a legitimate tax-exempt assignment).
 *
 * **Retry-safe by construction.** A re-run finds the default and finds no
 * product with a null group, and so changes nothing. It never deletes, never
 * nulls and never overwrites a group a product already holds. It does not
 * touch `updated_at` or `version`: a backfill is not an edit by anyone.
 *
 * It changes data and not shape, which is what the backfill phase is.
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

        $default = $connection->table('tax_groups')->where('is_default', true)->value('id');

        if ($default === null) {
            $default = (string) Str::ulid();
            // Not `RunnerClock::timestamp()`: the migration source rules read
            // any `->timestamp(` call as a TIMESTAMP column declaration.
            $now = (new RunnerClock)->now()->format(RunnerClock::PRECISION);

            $connection->table('tax_groups')->insert([
                'id' => $default,
                'created_at' => $now,
                'updated_at' => $now,
                'code' => 'STANDARD',
                'name' => 'Standard',
                'is_inclusive' => false,
                'is_default' => true,
                'is_active' => true,
            ]);
        }

        $connection->table('products')->whereNull('tax_group_id')->update(['tax_group_id' => $default]);
    }
};

<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Schema;

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 2 - the past_due_at column (Ruling 1, AC-11, AC-16)
|--------------------------------------------------------------------------
|
| Proves the migration's shape on the master stand-in (nullable, the
| compound index) and that it carries none of SourceRules's violations -
| the same closing check every other migration file gets (plan step 2:
| "SourceRules::inspect() returns nothing for the file").
*/

uses(TestCase::class);

it('adds a nullable past_due_at column to subscriptions, with its index', function () {
    PlatformSeed::migrate();

    $connection = PlatformSeed::connection();

    expect(Schema::connection('master')->hasColumn('subscriptions', 'past_due_at'))->toBeTrue();

    // Nullable: an insert naming no past_due_at at all must succeed, and the
    // column must read back null rather than refusing the write.
    $tenantId = PlatformSeed::tenant();
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'active');

    expect($connection->table('subscriptions')->where('id', $subscriptionId)->value('past_due_at'))->toBeNull();

    // The compound index on (status, past_due_at) exists on the live stand-in.
    $indexes = Schema::connection('master')->getIndexes('subscriptions');
    $indexedColumns = array_map(
        static fn (array $index): array => $index['columns'],
        $indexes,
    );

    $hasCompoundIndex = false;

    foreach ($indexedColumns as $columns) {
        if (in_array('status', $columns, true) && in_array('past_due_at', $columns, true)) {
            $hasCompoundIndex = true;

            break;
        }
    }

    expect($hasCompoundIndex)->toBeTrue();
});

it('carries no SourceRules violation, with a planted-violation control proving the scan can fail', function () {
    $path = dirname(__DIR__, 4).'/modules/host/database/migrations/2026_10_28_000601_add_past_due_at_to_subscriptions_table.php';

    expect(is_file($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    expect(SourceRules::inspect($source, MigrationTree::Master))->toBe([]);

    // Positive control: the same scan, over a fixture migration that drops a
    // table it did not create, is not empty - so an empty result above means
    // "nothing found", not "the scan never runs".
    $violating = <<<'PHP'
<?php

/**
 * @phase expand
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
PHP;

    expect(SourceRules::inspect($violating, MigrationTree::Master))->not->toBe([]);
});

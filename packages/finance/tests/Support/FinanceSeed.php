<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Support;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Finance\Registry\FinancePermissions;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Rows in a fixture tenant's finance tables, written directly (T-032 plan
 * section 3, the `CustomersSeed` shape).
 *
 * **Direct inserts, deliberately, and not this task's own handlers**: a
 * handler test seeded through another handler would be answering two
 * questions at once. Foreign keys are enforced on the fixture connection
 * ({@see AccessTenantFixture::enter()}).
 *
 * **The fixture tenant holds no seeded chart yet.** The shipped seed
 * migration arrives in chunk D, and the fixture's template is built from the
 * shipped tree, so from that step the tables are not empty at the start of a
 * test. Every helper here therefore works from codes a four-digit seed code
 * cannot collide with.
 */
final class FinanceSeed
{
    public const NOW = '2026-11-10 09:00:00.000';

    public static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = DB::connection(AccessTenantFixture::connectionName());

        return $connection;
    }

    /**
     * The seven finance keys as `permissions` rows, the way the registry sync
     * would have written them. Idempotent.
     */
    public static function registerPermissions(): void
    {
        foreach (FinancePermissions::declarations() as $declaration) {
            self::connection()->table('permissions')->insertOrIgnore([
                'key' => $declaration->key,
                'module' => $declaration->module,
                'group' => $declaration->group,
                'description' => $declaration->description,
                'is_branch_scoped' => $declaration->isBranchScoped,
            ]);
        }
    }

    /**
     * An employee holding exactly these finance keys (and so none of the
     * others). Registers the keys first.
     */
    public static function actor(string $code, string ...$keys): string
    {
        self::registerPermissions();

        return AccessSeed::actorHolding($code, array_values($keys));
    }

    /**
     * An employee holding every finance key.
     */
    public static function admin(string $code = 'finance-admin'): string
    {
        return self::actor($code, ...FinancePermissions::KEYS);
    }

    /**
     * An employee holding no finance key at all.
     */
    public static function nobody(string $code = 'finance-nobody'): string
    {
        return self::actor($code);
    }

    /**
     * One account row. A parent in `$overrides['parent_id']` fixes `path` and
     * `depth` from the stored parent, so the row is consistent unless the
     * test overrides them on purpose.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function account(string $code, array $overrides = []): string
    {
        $id = (string) ($overrides['id'] ?? Str::ulid());
        $parentId = $overrides['parent_id'] ?? null;

        $path = $id;
        $depth = 0;

        if ($parentId !== null) {
            $parent = self::connection()->table('accounts')->where('id', $parentId)->first();
            Assert::assertNotNull($parent, 'The seeded parent does not exist.');
            $path = $parent->path.'/'.$id;
            $depth = (int) $parent->depth + 1;
        }

        self::connection()->table('accounts')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'created_by' => null,
            'updated_by' => null,
            'code' => $code,
            'name' => 'Account '.$code,
            'description' => null,
            'type' => 'asset',
            'subtype' => null,
            'parent_id' => null,
            'path' => $path,
            'depth' => $depth,
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_system' => false,
            'control_type' => 'none',
            'currency' => null,
            'is_active' => true,
            'sort_order' => 0,
            'opening_balance' => null,
            'opening_balance_date' => null,
        ], $overrides));

        return $id;
    }

    /**
     * A header (non-postable) account, the only kind that takes children.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function header(string $code, array $overrides = []): string
    {
        return self::account($code, array_merge(['is_postable' => false], $overrides));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function row(string $id): ?array
    {
        $row = self::connection()->table('accounts')->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Every account row, keyed by id, for before-and-after comparisons.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function snapshot(): array
    {
        $rows = [];

        foreach (self::connection()->table('accounts')->orderBy('id')->get() as $row) {
            $rows[(string) $row->id] = (array) $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function audit(): array
    {
        return self::connection()->table('audit_logs')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * The one audit row an operation wrote, without the three columns that
     * differ run to run (`id`, `created_at`, `ip`), with `before`, `after`
     * and `changed_fields` decoded: compared as a whole row.
     *
     * @return array<string, mixed>
     */
    public static function onlyAuditRow(): array
    {
        $rows = self::audit();

        Assert::assertCount(1, $rows, 'Exactly one audit row was expected.');

        $row = $rows[0];
        unset($row['id'], $row['created_at'], $row['ip']);

        foreach (['before', 'after', 'changed_fields'] as $json) {
            $row[$json] = $row[$json] === null ? null : json_decode((string) $row[$json], true);
        }

        return $row;
    }
}

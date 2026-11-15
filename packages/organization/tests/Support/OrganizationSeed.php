<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Support;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Support\OrganizationRefusal;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Rows in a fixture tenant's organization tables, written directly, and the
 * refusal helper the slice tests share (T-014 section 3).
 *
 * **Direct inserts, deliberately**, as `AccessSeed` is: a slice test seeded
 * through the other slices would be answering two questions at once. The
 * fixture connection enforces foreign keys ({@see AccessTenantFixture::enter()}),
 * so a seed naming a branch or location that does not exist fails here rather
 * than producing a row production could not hold.
 *
 * **This class may call `AccessSeed`** - the stated new edge for test code
 * only (plan section 5): employees and branches are access's and the tests
 * of this package need both.
 */
final class OrganizationSeed
{
    public const NOW = '2026-10-22 09:00:00.000';

    public static function connection(): Connection
    {
        return AccessSeed::connection();
    }

    /**
     * A real branch, active unless asked otherwise.
     */
    public static function branch(bool $active = true): string
    {
        return AccessSeed::branch($active);
    }

    /**
     * A location.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function location(?string $branchId, string $code, string $type = 'warehouse', array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('locations')->insert(array_merge([
            'id' => $id,
            'branch_id' => $branchId,
            'code' => $code,
            'name' => 'Location '.$code,
            'type' => $type,
            'allow_negative_stock' => false,
            'is_sellable_from' => $type === 'branch_store',
            'is_purchasable_to' => true,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides));

        return $id;
    }

    /**
     * A terminal, `pending` unless asked otherwise.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function terminal(string $branchId, string $locationId, string $code, string $status = 'pending', array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('terminals')->insert(array_merge([
            'id' => $id,
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'code' => $code,
            'name' => 'Terminal '.$code,
            'status' => $status,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides));

        return $id;
    }

    /**
     * Every audit row, oldest first, as arrays.
     *
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
     * and `changed_fields` decoded: compared as a whole row, so a column
     * that quietly goes missing or null fails (T-010 Ruling 11).
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

    /**
     * Run an operation that must be refused, and hand back the refusal.
     *
     * It fails when nothing is thrown, which is the half a bare `try`/`catch`
     * keeps forgetting.
     *
     * @param  callable(): mixed  $operation
     */
    public static function refusal(OrganizationRefusal $reason, callable $operation, ?string $context = null): OrganizationRefused
    {
        try {
            $operation();
        } catch (OrganizationRefused $refused) {
            $where = $context === null ? '' : " [{$context}]";

            Assert::assertSame(
                $reason,
                $refused->reason,
                "Expected [{$reason->value}] and got [{$refused->reason->value}]{$where}: {$refused->getMessage()}"
            );

            return $refused;
        }

        Assert::fail('The operation was expected to be refused and succeeded.');
    }

    /**
     * How many rows each of the organization tables and the audit table hold.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = [];

        foreach (['branches', 'locations', 'terminals', 'devices', 'terminal_credentials', 'audit_logs', 'access_log', 'settings'] as $table) {
            $counts[$table] = self::connection()->table($table)->count();
        }

        return $counts;
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Support;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Rows in a fixture tenant's catalog tables, written directly (T-027 plan
 * section 3, the `AccessSeed`/`OrganizationSeed` shape).
 *
 * **Direct inserts, deliberately, and not this task's own handlers.** A
 * handler test seeded through another handler would be answering two
 * questions at once. Foreign keys are enforced on the fixture connection
 * ({@see AccessTenantFixture::enter()}), so a
 * seed naming a row that does not exist fails here rather than producing a
 * row production could not hold.
 */
final class CatalogSeed
{
    public const NOW = '2026-11-08 09:00:00.000';

    public static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        return $connection;
    }

    /**
     * A unit of measure, with every column defaulted the way `units`
     * itself defaults it.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function unit(string $code, string $dimension = 'count', array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('units')->insert(array_merge([
            'id' => $id,
            'code' => $code,
            'name' => 'Unit '.$code,
            'dimension' => $dimension,
            'precision' => 4,
            'base_unit_id' => null,
            'factor' => null,
            'is_active' => true,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides));

        return $id;
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
}

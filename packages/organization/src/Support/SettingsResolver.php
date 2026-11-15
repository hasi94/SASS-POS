<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * The effective value of every registered setting for a place (T-014 AC-44,
 * AC-48, AC-50).
 *
 * For a terminal T at branch X the first present of **(terminal, T)**,
 * **(branch, X)**, **(company, null)** and the registry default. A branch
 * uses the last three, the company the last two. **Rows of scope `employee`
 * are never read**: the query names the three layers, so a stray employee row
 * cannot win by accident (AC-51).
 *
 * **Nothing is cached, and nothing is memoised** (Ruling 15): every call
 * reads the tenant connection it is given, so a value is the one just
 * written and a second tenant on the same worker cannot see the first's.
 * There is no state in this class to key by tenant.
 *
 * A secret is reported as `is_set` with its source layer and **no value**,
 * including its default: a setting marked secret has no value on the wire at
 * any layer (AC-47).
 */
final class SettingsResolver
{
    public const DEFAULT = 'default';

    /** Most specific first. */
    private const LAYERS = [SettingScope::TERMINAL, SettingScope::BRANCH, SettingScope::COMPANY];

    /**
     * @param  bool  $posOnly  only the keys the POS may receive: `sync_to_pos` and not `is_secret`
     * @return list<array<string, mixed>> ordered by key
     */
    public static function effective(Connection $connection, ?string $branchId, ?string $terminalId, bool $posOnly = false): array
    {
        $registry = $connection->table('settings_registry')->orderBy('key');

        if ($posOnly) {
            $registry->where('sync_to_pos', true)->where('is_secret', false);
        }

        $rows = $connection->table('settings')
            ->where(static function (Builder $any) use ($branchId, $terminalId): void {
                $any->where(static function (Builder $company): void {
                    $company->where('scope_type', SettingScope::COMPANY)->whereNull('scope_id');
                });

                if ($branchId !== null) {
                    $any->orWhere(static function (Builder $branch) use ($branchId): void {
                        $branch->where('scope_type', SettingScope::BRANCH)->where('scope_id', $branchId);
                    });
                }

                if ($terminalId !== null) {
                    $any->orWhere(static function (Builder $terminal) use ($terminalId): void {
                        $terminal->where('scope_type', SettingScope::TERMINAL)->where('scope_id', $terminalId);
                    });
                }
            })
            ->get();

        /** @var array<string, array<string, mixed>> $overrides */
        $overrides = [];

        foreach ($rows as $row) {
            $overrides[(string) $row->key][(string) $row->scope_type] = $row->value;
        }

        $items = [];

        foreach ($registry->get() as $definition) {
            $key = (string) $definition->key;
            $source = self::DEFAULT;
            $raw = $definition->default_value;

            foreach (self::LAYERS as $layer) {
                if (array_key_exists($layer, $overrides[$key] ?? [])) {
                    $source = $layer;
                    $raw = $overrides[$key][$layer];

                    break;
                }
            }

            if ((bool) $definition->is_secret) {
                $items[] = ['key' => $key, 'is_set' => $source !== self::DEFAULT, 'source' => $source];

                continue;
            }

            $items[] = [
                'key' => $key,
                'value' => $raw === null ? null : json_decode((string) $raw, true),
                'source' => $source,
            ];
        }

        return $items;
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Support;

use Illuminate\Support\Str;

/**
 * Registry and override rows for the settings tests (T-014 step 14).
 *
 * **T-014 ships no setting keys** (Ruling 9), so every settings test declares
 * its own fixture keys here, by direct insert: the registry has no writer in
 * the product, and a test that went through one would be proving a
 * declaration mechanism this task does not have. `$validation` may be a raw
 * string so a malformed registry row can be planted.
 */
final class SettingsSeed
{
    /**
     * @param  array<string, mixed>|string|null  $validation
     */
    public static function register(
        string $key,
        string $type = 'string',
        mixed $default = null,
        array|string|null $validation = null,
        bool $sync = false,
        bool $secret = false,
    ): void {
        OrganizationSeed::connection()->table('settings_registry')->insert([
            'key' => $key,
            'module' => 'fixture',
            'type' => $type,
            'default_value' => $default === null ? null : json_encode($default),
            'validation' => $validation === null ? null : (is_string($validation) ? $validation : json_encode($validation)),
            'sync_to_pos' => $sync,
            'is_secret' => $secret,
            'plugin_name' => null,
        ]);
    }

    /**
     * An override row, written directly.
     */
    public static function override(string $scopeType, ?string $scopeId, string $key, mixed $value, ?string $actor = null): string
    {
        $id = (string) Str::ulid();

        OrganizationSeed::connection()->table('settings')->insert([
            'id' => $id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'key' => $key,
            'value' => json_encode($value),
            'updated_by' => $actor,
            'created_at' => OrganizationSeed::NOW,
            'updated_at' => OrganizationSeed::NOW,
        ]);

        return $id;
    }

    public static function clearOverrides(): void
    {
        OrganizationSeed::connection()->table('settings')->delete();
    }

    /**
     * Two branches and three terminals: T1 and T2 at X, T3 at Y.
     *
     * @return array{x: string, y: string, t1: string, t2: string, t3: string}
     */
    public static function places(): array
    {
        $x = OrganizationSeed::branch();
        $y = OrganizationSeed::branch();
        $sx = OrganizationSeed::location($x, 'SX', 'branch_store', ['is_sellable_from' => true]);
        $sy = OrganizationSeed::location($y, 'SY', 'branch_store', ['is_sellable_from' => true]);

        return [
            'x' => $x,
            'y' => $y,
            't1' => OrganizationSeed::terminal($x, $sx, 'T1', 'active'),
            't2' => OrganizationSeed::terminal($x, $sx, 'T2', 'active'),
            't3' => OrganizationSeed::terminal($y, $sy, 'T3', 'active'),
        ];
    }

    /**
     * The settings rows, whole, ordered, for "nothing changed" comparisons.
     */
    public static function snapshot(): string
    {
        return (string) json_encode(OrganizationSeed::connection()->table('settings')->orderBy('id')->get()->all());
    }
}

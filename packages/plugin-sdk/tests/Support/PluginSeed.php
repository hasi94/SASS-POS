<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows in the real master schema, for the plugin-sdk slice tests (mirrors
 * `packages/platform/tests/Support/PlatformSeed.php`).
 */
final class PluginSeed
{
    public static function migrate(): void
    {
        expect(config('database.connections.master.driver'))->toBe('sqlite')
            ->and(config('database.connections.master.database'))->toBe(':memory:');

        expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);
    }

    public static function connection(): Connection
    {
        return DB::connection('master');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function tenant(string $code = 'tenant-a', string $status = 'active', array $attributes = []): string
    {
        $id = (string) Str::ulid();
        $now = app(RunnerClock::class)->timestamp();

        self::connection()->table('tenants')->insert(array_merge([
            'id' => $id,
            'sequence' => self::connection()->table('tenants')->count() + 1,
            'code' => $code,
            'slug' => $code,
            'name' => 'Shop '.$code,
            'status' => $status,
            'country' => 'LK',
            'currency' => 'LKR',
            'timezone' => 'Asia/Colombo',
            'business_type' => 'retail',
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function user(array $attributes = []): string
    {
        $id = (string) Str::ulid();
        $now = app(RunnerClock::class)->timestamp();

        self::connection()->table('users')->insert(array_merge([
            'id' => $id,
            'name' => 'Operator',
            'email' => 'operator-'.strtolower(substr($id, -8)).'@example.com',
            'password_hash' => 'not-a-hash',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function licence(string $tenantId, string $subject, string $type = 'plugin', string $status = 'active', array $attributes = []): string
    {
        $id = (string) Str::ulid();
        $now = app(RunnerClock::class)->timestamp();

        self::connection()->table('licences')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $tenantId,
            'type' => $type,
            'subject' => $subject,
            'licence_key' => str_pad(substr($id, -20), 64, '0'),
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pluginRow(string $name): ?array
    {
        $row = self::connection()->table('plugins')->where('name', $name)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function tenantPluginRow(string $tenantId, string $pluginId): ?array
    {
        $row = self::connection()->table('tenant_plugins')
            ->where('tenant_id', $tenantId)
            ->where('plugin_id', $pluginId)
            ->first();

        return $row === null ? null : (array) $row;
    }
}

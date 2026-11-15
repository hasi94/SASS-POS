<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Contracts\Platform\FeatureFlags;
use Flexipos\Contracts\Platform\PlanLimits;
use Flexipos\Contracts\Platform\TenantSeats;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\MappingSource;
use Flexipos\Platform\Support\EntitlementSource;
use Flexipos\Platform\Support\EvictsEntitlements;
use Flexipos\Platform\Support\MasterEntitlementSource;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T-016: rows in the real master schema, for the platform slice tests
 * (mirrors `packages/kernel/tests/Support/master-mapping-fixture.php`).
 */
final class PlatformSeed
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
     * Bind the real master entitlement source in place of whatever
     * `MappingSource::isConfigStandIn()` would otherwise select - the control
     * several criteria need "on the master source" rather than on the
     * config stand-in.
     */
    public static function bindMasterSource(): void
    {
        app()->instance(EntitlementSource::class, app(MasterEntitlementSource::class));
        app()->instance(EvictsEntitlements::class, new class implements EvictsEntitlements
        {
            public function evict(string $tenantId): void {}
        });

        // PlanLimits/FeatureFlags/TenantSeats are bound as singletons and may
        // already have been resolved - with the source that was in effect
        // then - before this call. Forgetting them makes the next resolution
        // rebuild against the source just bound above.
        app()->forgetInstance(PlanLimits::class);
        app()->forgetInstance(FeatureFlags::class);
        app()->forgetInstance(TenantSeats::class);
    }

    public static function assertConfigStandIn(): void
    {
        expect(MappingSource::isConfigStandIn(app('config'), app()))->toBeTrue();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function plan(array $attributes = []): string
    {
        $id = (string) Str::ulid();
        $now = app(RunnerClock::class)->timestamp();

        self::connection()->table('plans')->insert(array_merge([
            'id' => $id,
            'code' => 'plan-'.strtolower(substr($id, -8)),
            'name' => 'Fixture plan',
            'price' => 10,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'trial_days' => 0,
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));

        return $id;
    }

    public static function planLimit(string $planId, string $key, int $value): void
    {
        self::connection()->table('plan_limits')->insert([
            'plan_id' => $planId,
            'limit_key' => $key,
            'value' => $value,
        ]);
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
    public static function subscription(string $tenantId, string $planId, string $status = 'active', array $attributes = []): string
    {
        $id = (string) Str::ulid();
        $now = app(RunnerClock::class)->timestamp();

        self::connection()->table('subscriptions')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $tenantId,
            'plan_id' => $planId,
            'status' => $status,
            'quantity' => 1,
            'unit_price' => 10,
            'currency' => 'USD',
            'starts_at' => $now,
            'current_period_start' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function licence(string $tenantId, string $type = 'platform', string $status = 'active', array $attributes = []): string
    {
        $id = (string) Str::ulid();
        $now = app(RunnerClock::class)->timestamp();

        self::connection()->table('licences')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $tenantId,
            'type' => $type,
            'subject' => 'platform',
            'licence_key' => str_pad(substr($id, -20), 64, '0'),
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));

        return $id;
    }

    public static function featureFlag(string $key, bool $defaultEnabled = false, string $ownerModule = 'platform'): void
    {
        self::connection()->table('feature_flags')->insert([
            'key' => $key,
            'default_enabled' => $defaultEnabled,
            'owner_module' => $ownerModule,
            'is_plugin_flag' => false,
        ]);
    }

    public static function planFeature(string $planId, string $key, mixed $value): void
    {
        self::connection()->table('plan_features')->insert([
            'plan_id' => $planId,
            'feature_key' => $key,
            'value' => $value === null ? null : json_encode($value),
        ]);
    }

    public static function tenantFeatureFlag(string $tenantId, string $key, bool $enabled): void
    {
        $now = app(RunnerClock::class)->timestamp();

        self::connection()->table('tenant_feature_flags')->insert([
            'tenant_id' => $tenantId,
            'flag_key' => $key,
            'enabled' => $enabled,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function platformSetting(string $key, mixed $value, string $type = 'integer'): void
    {
        self::connection()->table('platform_settings')->insert([
            'key' => $key,
            'value' => json_encode($value),
            'type' => $type,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function tenantRow(string $tenantId): ?array
    {
        $row = self::connection()->table('tenants')->where('id', $tenantId)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function auditRows(string $action): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            self::connection()->table('platform_audit_logs')->where('action', $action)->get()->all()
        );
    }
}

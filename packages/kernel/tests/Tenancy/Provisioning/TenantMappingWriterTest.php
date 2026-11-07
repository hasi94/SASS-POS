<?php

use Flexipos\Kernel\Tenancy\Contracts\EvictsTenantMappings;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Provisioning\TenantMappingWriter;
use Flexipos\Kernel\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A spying mapping source: records every evicted tenant id, finds nothing
 * (this test never reads through it) - the AC-19/D-19 spy the plan calls
 * for.
 */
final class SpyingTenantDatabaseRepository implements TenantDatabaseRepository, EvictsTenantMappings
{
    /** @var list<string> */
    public array $evicted = [];

    public function find(string $tenantId): ?TenantDatabase
    {
        return null;
    }

    public function evict(string $tenantId): void
    {
        $this->evicted[] = $tenantId;
    }
}

/**
 * @return array{0: string, 1: string}
 */
function mappingWriterSeedTenant(): array
{
    Artisan::call('migrate', ['--database' => 'master', '--force' => true]);

    $serverId = (string) Str::ulid();
    $tenantId = (string) Str::ulid();

    DB::connection('master')->table('database_servers')->insert([
        'id' => $serverId,
        'code' => 'SRV1',
        'name' => 'Server 1',
        'host' => '127.0.0.1',
        'port' => 3306,
        'engine' => 'mysql',
        'tls_mode' => 'required',
        'provisioner_credential_ref' => 'env://ADMIN',
        'tier' => 'shared',
        'max_tenants' => 100,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('master')->table('tenants')->insert([
        'id' => $tenantId,
        'sequence' => 1,
        'code' => 'TEN001',
        'slug' => 'ten001',
        'name' => 'Ten 001',
        'status' => 'provisioning',
        'country' => 'LK',
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'business_type' => 'retail',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenantId, $serverId];
}

/**
 * @return array<string, mixed>
 */
function mappingWriterRow(string $tenantId, string $serverId): array
{
    return [
        'tenant_id' => $tenantId,
        'server_id' => $serverId,
        'database_identifier' => 'TEN001',
        'database_name' => 'flx_t_000001',
        'app_username' => 'flx_t000001_app',
        'app_credential_ref' => 'file:///tmp/tenant_000001_app',
        'migrate_username' => 'flx_t000001_mig',
        'migrate_credential_ref' => 'file:///tmp/tenant_000001_mig',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'status' => 'provisioning',
        'profile' => 'standard',
    ];
}

it('inserts exactly the given row and evicts', function () {
    [$tenantId, $serverId] = mappingWriterSeedTenant();
    $spy = new SpyingTenantDatabaseRepository;
    $writer = new TenantMappingWriter(app('db'), app('config'), app(\Flexipos\Kernel\Schema\Support\RunnerClock::class), $spy);

    $row = $writer->insert(mappingWriterRow($tenantId, $serverId));

    expect($row['database_name'])->toBe('flx_t_000001')
        ->and($row['status'])->toBe('provisioning')
        ->and($spy->evicted)->toBe([$tenantId]);
});

it('is idempotent when the row already exists, and does not re-insert', function () {
    [$tenantId, $serverId] = mappingWriterSeedTenant();
    $spy = new SpyingTenantDatabaseRepository;
    $writer = new TenantMappingWriter(app('db'), app('config'), app(\Flexipos\Kernel\Schema\Support\RunnerClock::class), $spy);

    $writer->insert(mappingWriterRow($tenantId, $serverId));
    $writer->insert(mappingWriterRow($tenantId, $serverId));

    expect(DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->count())->toBe(1);
});

it('activates with compare-and-set: a second call affects nothing', function () {
    [$tenantId, $serverId] = mappingWriterSeedTenant();
    $spy = new SpyingTenantDatabaseRepository;
    $writer = new TenantMappingWriter(app('db'), app('config'), app(\Flexipos\Kernel\Schema\Support\RunnerClock::class), $spy);

    $writer->insert(mappingWriterRow($tenantId, $serverId));

    expect($writer->activate($tenantId))->toBeTrue();
    expect(DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->value('status'))
        ->toBe('active');

    expect($writer->activate($tenantId))->toBeFalse();
});

it('archives, writing archived and evicting', function () {
    [$tenantId, $serverId] = mappingWriterSeedTenant();
    $spy = new SpyingTenantDatabaseRepository;
    $writer = new TenantMappingWriter(app('db'), app('config'), app(\Flexipos\Kernel\Schema\Support\RunnerClock::class), $spy);

    $writer->insert(mappingWriterRow($tenantId, $serverId));
    $spy->evicted = [];

    $writer->archive($tenantId);

    expect(DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->value('status'))
        ->toBe('archived')
        ->and($spy->evicted)->toBe([$tenantId]);
});

it('archiving a tenant with no mapping row yet is a no-op, not an error', function () {
    [$tenantId] = mappingWriterSeedTenant();
    $spy = new SpyingTenantDatabaseRepository;
    $writer = new TenantMappingWriter(app('db'), app('config'), app(\Flexipos\Kernel\Schema\Support\RunnerClock::class), $spy);

    $writer->archive($tenantId);

    expect($spy->evicted)->toBe([]);
});

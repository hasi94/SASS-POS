<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * T-026 steps 12 to 15: a tenant mapped through the **real** master schema
 * (`tenant_databases`/`database_servers`), whose database is a real SQLite
 * file holding the **real shipped tenant tree** - what `seed_core`,
 * `create_owner`, `verify` and `activate` all need to call the real
 * `SyncPermissionRegistry`/`CreateBranch`/`AttachBranch` handlers against,
 * reached only through `Tenancy::run()` exactly as production does it.
 *
 * **Built on a per-process migrated template**, the same shape
 * `Flexipos\Access\Tests\Support\AccessTenantFixture` already uses and for
 * the same measured reason: migrating the whole shipped tree into a file
 * SQLite database is expensive (SQLite fsyncs every statement), so it is
 * paid once per process here and every {@see self::create()} is a cheap
 * file copy.
 *
 * **Why this is not `AccessTenantFixture` itself.** That fixture's tenants
 * are mapped through the suite's `config` mapping stand-in
 * (`InMemoryTenantDatabaseRepository`), with no master `tenant_databases`
 * row at all - exactly right for `packages/access`'s own slice tests, and
 * exactly wrong here: `seed_core`/`create_owner`/`verify` each re-read
 * `tenant_databases.status` from the **master** before entering (Ruling 6),
 * so this fixture writes a real master row instead.
 */
final class ProvisioningTenantFixture
{
    private const TEMPLATE_CONNECTION = 'platform_provisioning_fixture_template';

    private const FIXTURE_CREDENTIAL = 'env://T026_FIXTURE_PASSWORD';

    private static ?string $template = null;

    public static function root(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'t026-provisioning-fixture');
    }

    /**
     * Point the mapping reader at the real master schema and publish the
     * one credential every fixture row shares.
     */
    public static function boot(): void
    {
        PlatformSeed::migrate();

        File::ensureDirectoryExists(self::root());

        config(['tenancy.mapping.source' => 'master']);
        config(['schema.max_attempts' => 3]);
        config(['schema.claim_ttl_minutes' => 30]);
        config(['schema.per_server_limit' => 2]);
        app()->forgetInstance(TenantDatabaseRepository::class);

        putenv('T026_FIXTURE_PASSWORD=f1xture-DO-NOT-LEAK-72ab');
        $_ENV['T026_FIXTURE_PASSWORD'] = 'f1xture-DO-NOT-LEAK-72ab';
        $_SERVER['T026_FIXTURE_PASSWORD'] = 'f1xture-DO-NOT-LEAK-72ab';
    }

    /**
     * Remove every per-test database this fixture copied (the template
     * itself is per-process and is left for the next test in this file).
     */
    public static function forget(): void
    {
        foreach (glob(self::root().DIRECTORY_SEPARATOR.'*.sqlite') ?: [] as $path) {
            if (basename($path) !== 'template.sqlite') {
                @unlink($path);
            }
        }

        putenv('T026_FIXTURE_PASSWORD');
        unset($_ENV['T026_FIXTURE_PASSWORD'], $_SERVER['T026_FIXTURE_PASSWORD']);
    }

    /**
     * Delete the whole fixture root, including the template. From an
     * `afterAll` hook.
     */
    public static function deleteRoot(): void
    {
        foreach (glob(self::root().DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        clearstatcache();

        if (is_dir(self::root())) {
            @rmdir(self::root());
        }
    }

    /**
     * One `tenants` row, one `database_servers` row and one `tenant_databases`
     * row naming a fresh copy of the migrated template - a tenant ready for
     * `seed_core` onward to run against.
     *
     * @param  array<string, mixed>  $tenantOverrides  applied to the `tenants` insert
     * @param  array<string, mixed>  $mappingOverrides  applied to the `tenant_databases` insert
     * @return array{tenant_id: string, server_id: string, database_path: string}
     */
    public static function create(string $code, array $tenantOverrides = [], array $mappingOverrides = []): array
    {
        $status = (string) ($tenantOverrides['status'] ?? 'provisioning');
        $tenantId = PlatformSeed::tenant($code, $status, $tenantOverrides);

        $serverId = (string) Str::ulid();
        $now = app(RunnerClock::class)->timestamp();

        PlatformSeed::connection()->table('database_servers')->insert([
            'id' => $serverId,
            'code' => 'srv-'.strtolower($code),
            'name' => 'Server for '.$code,
            'host' => '127.0.0.1',
            'port' => 3306,
            'engine' => 'sqlite',
            'tls_mode' => 'disabled',
            'provisioner_credential_ref' => self::FIXTURE_CREDENTIAL,
            'tier' => 'shared',
            'max_tenants' => 100,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $path = self::root().DIRECTORY_SEPARATOR.$tenantId.'.sqlite';
        File::delete($path);
        File::copy(self::template(), $path);

        PlatformSeed::connection()->table('tenant_databases')->insert(array_merge([
            'tenant_id' => $tenantId,
            'server_id' => $serverId,
            'database_identifier' => $code,
            'database_name' => $path,
            'app_username' => 'flx_'.strtolower($code).'_app',
            'app_credential_ref' => self::FIXTURE_CREDENTIAL,
            'migrate_username' => 'flx_'.strtolower($code).'_mig',
            'migrate_credential_ref' => self::FIXTURE_CREDENTIAL,
            'credential_version' => 1,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'status' => 'provisioning',
            'schema_version' => tenantMigrationFileCount(),
            'profile' => 'standard',
            'created_at' => $now,
            'updated_at' => $now,
        ], $mappingOverrides));

        return ['tenant_id' => $tenantId, 'server_id' => $serverId, 'database_path' => $path];
    }

    /**
     * The migrated template, built once per process.
     */
    private static function template(): string
    {
        if (self::$template !== null && is_file(self::$template)) {
            return self::$template;
        }

        config()->set('database.connections.'.self::TEMPLATE_CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge(self::TEMPLATE_CONNECTION);

        $exit = Artisan::call('migrate', [
            '--database' => self::TEMPLATE_CONNECTION,
            '--path' => tenantMigrationPath(),
            '--force' => true,
        ]);

        if ($exit !== 0) {
            throw new RuntimeException('ProvisioningTenantFixture could not apply the shipped tenant tree to its template.');
        }

        File::ensureDirectoryExists(self::root());
        $path = self::root().DIRECTORY_SEPARATOR.'template.sqlite';
        File::delete($path);

        DB::connection(self::TEMPLATE_CONNECTION)
            ->statement("vacuum into '".str_replace("'", "''", $path)."'");

        DB::purge(self::TEMPLATE_CONNECTION);

        if (! is_file($path)) {
            throw new RuntimeException('ProvisioningTenantFixture could not write its template.');
        }

        self::$template = $path;

        return $path;
    }
}

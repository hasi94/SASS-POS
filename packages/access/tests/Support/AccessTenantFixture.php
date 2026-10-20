<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * T-010 step 5 - two fixture tenants whose databases hold the shipped tenant
 * tree, reachable only through `Tenancy::enter()`.
 *
 * **Built on `packages/kernel/tests/Support/tenancy-fixture.php`, not beside
 * it.** That fixture already owns the two SQLite files, the credentials in the
 * environment, the mapping stand-in and the teardown; a second fixture
 * declaring its own would be a second answer to "which tenants exist", and the
 * first thing to go out of step. What this class adds is exactly two facts the
 * kernel fixture has no reason to know: the databases hold the migrated tenant
 * tree, and their mapping rows carry a `schema_version` the serving gate
 * admits.
 *
 * **Nothing here builds a tenant connection.** T-009 AC-52 still holds: the
 * only way a test reaches a fixture tenant's data is `Tenancy::enter()`, which
 * configures the `tenant` stanza from the mapping record. The one connection
 * this class configures itself is {@see self::TEMPLATE_CONNECTION}, which
 * points at a template file that is not any tenant's database and is never
 * mapped to one - the same shape `TenantAccessKeysTest` uses to apply the tree
 * to a stand-in.
 *
 * **Why a template and a file copy instead of migrating each tenant.** The
 * tree is fourteen migrations and SQLite fsyncs every statement, so applying
 * it to a file database is the expensive shape - the host's
 * `QaRealApplierOnStandInTest` pays ~32 s doing it for three tenants. Here it
 * is paid **once per process**: the tree is applied to one template file, and
 * every `boot()` copies that file over each tenant's database. A copy is a
 * file operation, not fourteen migrations, so the per-test cost is flat as the
 * tree grows and the per-test isolation is total - each test starts from a
 * byte-identical fresh database.
 *
 * The template lives under `storage/app/`, which is git-ignored, analysed by
 * nothing and collected by nothing; it is deleted on the way in as well as at
 * shutdown, so a killed process cannot leave a stale one behind that a later
 * run would believe.
 */
final class AccessTenantFixture
{
    /**
     * The two tenants of the kernel fixture whose databases this class
     * migrates.
     *
     * The kernel fixture maps seven more - suspended, migrating, read_only,
     * failed, provisioning, unopenable, wrong-driver - and every one of them
     * points at `tenant-a`'s file or at no file at all, so they keep working
     * unchanged and stay available as the non-servable inputs a gate-ordering
     * case needs.
     *
     * @var list<string>
     */
    public const TENANTS = ['tenant-a', 'tenant-b'];

    /**
     * The connection the template is built through.
     *
     * Its own name, never `tenant`: that stanza is T-003's sentinel and is
     * owned by `Tenancy`. A test that reached a fixture tenant through this
     * one would be proving something about a connection this fixture built,
     * which is the thing AC-52 forbids.
     */
    public const TEMPLATE_CONNECTION = 'access_fixture_template';

    /**
     * The migrated template of each tenant, once it exists in this process.
     *
     * @var array<string, string>
     */
    private static array $templates = [];

    /**
     * Boot the kernel fixture, give both tenants a migrated database, and put
     * a `schema_version` on their mapping rows.
     *
     * `$schemaVersion` defaults to the top of the shipped tree, which is what
     * the serving gate requires. Passing `0` is the negative control: the
     * identical `enter()` must then be refused with `TenantSchemaBehind`, and
     * "refused" alone is not the control - the pair is, because a broken
     * fixture also produces a refusal.
     */
    public static function boot(?int $schemaVersion = null): void
    {
        self::requireKernelFixture();

        tenancyBootFixture();

        $templates = self::templates();

        foreach (self::TENANTS as $tenant) {
            $path = tenancyFixtureDatabasePath($tenant);

            File::delete($path);
            File::copy($templates[$tenant], $path);
        }

        self::mapAt($schemaVersion ?? self::topVersion());
    }

    /**
     * Enter a fixture tenant, with its foreign keys actually enforced.
     *
     * **Measured 2026-10-20, and it is a gap in the stand-in that nothing
     * else closes.** `Tenancy::writeTenantConnection()` builds the `tenant`
     * stanza wholesale from the mapping record and it carries no
     * `foreign_key_constraints` key - it has no reason to, because the
     * platform's drivers are MySQL and MariaDB, which enforce foreign keys
     * whatever the client says. SQLite does not: it parses a `REFERENCES`
     * clause and then ignores it unless the connection issues
     * `PRAGMA foreign_keys = ON`. So a fixture tenant entered with plain
     * `tenancy()->enter()` accepts a `role_permissions` row naming a
     * permission that does not exist, and every later slice test would be
     * proving its handler's rules against a database laxer than production.
     *
     * The pragma is issued on the connection Tenancy built, not on one this
     * class made, so T-009 AC-52 is untouched; and it is issued only for
     * SQLite, because on the real engines there is nothing to turn on.
     */
    public static function enter(string $tenant = 'tenant-a'): void
    {
        tenancy()->enter($tenant);

        $connection = DB::connection(self::connectionName());

        if ($connection->getDriverName() === 'sqlite') {
            $connection->statement('pragma foreign_keys = on');

            // Durability is worthless to a database that is deleted at the
            // end of the test and rebuilt from a template at the start of
            // the next, and it is expensive: SQLite fsyncs every committed
            // statement, which is why the marker write cost 0.7 s a tenant
            // before it moved into the template. Nothing under test depends
            // on surviving a power cut.
            $connection->statement('pragma synchronous = off');
        }
    }

    /**
     * Leave whichever tenant is resolved, then enter this one.
     *
     * **A separate method, and `enter()` is deliberately not made tolerant.**
     * `Tenancy::enter()` refuses a second tenant while one is resolved, and
     * that refusal is T-003's own criterion: a process that slid from one
     * tenant to another without saying so is the shape a cross-tenant leak
     * hides in. A test that genuinely wants both tenants in one case - AC-45
     * asks for exactly that, the same worker answering for A and then for B -
     * says so here, in one call a reader can see, instead of every `enter()`
     * in the suite quietly acquiring the ability to do it.
     */
    public static function switchTo(string $tenant): void
    {
        if (tenancy()->current()->hasTenant()) {
            tenancy()->leave();
        }

        self::enter($tenant);
    }

    /**
     * The name of the connection Tenancy configures - read, never written as
     * the literal `tenant`.
     */
    public static function connectionName(): string
    {
        return (string) config('tenancy.connection', 'tenant');
    }

    /**
     * The highest version the shipped tenant tree can produce.
     *
     * Derived from the tree, never written as a literal: a catalogue version
     * is a migration's **position** in the set, so the top is the number of
     * files, and `tenantMigrationFileCount()` is the suite's one authority for
     * that count. A literal here would be the second home for a number that
     * moves every time a migration lands (FP-181).
     */
    public static function topVersion(): int
    {
        return tenantMigrationFileCount();
    }

    /**
     * Put both tenants at a given schema version and set the serving floor to
     * the top of the tree.
     *
     * The floor and the tenant's version are two different facts and are set
     * separately on purpose: the control case moves the **tenant** below a
     * floor that does not move, which is the input the gate actually reads.
     */
    public static function mapAt(int $schemaVersion): void
    {
        $mapping = tenancyFixtureMapping();

        foreach (self::TENANTS as $tenant) {
            $mapping[$tenant]['schema_version'] = $schemaVersion;
        }

        config()->set('tenancy.databases', $mapping);
        config()->set('tenancy.required_schema_version', self::topVersion());

        // Both repositories are built from configuration and both are
        // resolved as shared instances, so neither sees an edit made after it
        // was built.
        app()->forgetInstance(TenantDatabaseRepository::class);
        app()->forgetInstance(TenantMigrationTargetRepository::class);
        app()->forgetScopedInstances();
    }

    /**
     * Return the process to "no tenant resolved" and drop the credentials.
     *
     * Delegated whole to the kernel fixture: every piece of per-test state
     * this class touches beyond the two database files is state that fixture
     * already owns.
     */
    public static function forget(): void
    {
        self::requireKernelFixture();

        tenancyForgetFixture();
    }

    /**
     * Remove the shared fixture directory, from an `afterAll` hook.
     *
     * The template is deliberately **not** removed here: it is per-process,
     * not per-file, and deleting it would make every test file after the
     * first pay the tree apply again. Its own removal is registered as a
     * shutdown function when it is built.
     */
    public static function deleteRoot(): void
    {
        self::requireKernelFixture();

        tenancyDeleteFixtureRoot();
    }

    /**
     * One migrated template per tenant, built on first use.
     *
     * Three measurements on this machine, 2026-10-20, and each one moved the
     * shape of this method:
     *
     * - migrating the fourteen files straight into a **file** database costs
     *   ~12.6 s, because SQLite fsyncs every statement of every migration.
     *   Migrating into `:memory:` and writing the result out with a single
     *   `VACUUM INTO` costs ~1 s for the same bytes, and the product is the
     *   same database - `VACUUM INTO` writes a complete, consistent copy;
     * - writing the one-row `marker` table into each copied tenant database
     *   afterwards cost **~0.7 s per tenant per test** - 7 s of a 10.5 s
     *   file - because opening a file database and committing to it is two
     *   more fsyncs. So the marker is baked into the template instead;
     * - which means one template per tenant, because the marker is the one
     *   byte that differs between them. They are produced from a single
     *   in-memory tree, which is migrated once however many tenants there
     *   are.
     *
     * The honest stand-in is whatever the tests then read, and that is the
     * file: every test enters a real SQLite database through `Tenancy`.
     *
     * @return array<string, string>
     */
    private static function templates(): array
    {
        $built = true;

        foreach (self::TENANTS as $tenant) {
            $built = $built && isset(self::$templates[$tenant]) && is_file(self::$templates[$tenant]);
        }

        if ($built) {
            return self::$templates;
        }

        $directory = storage_path('app'.DIRECTORY_SEPARATOR.'access-fixture');

        File::ensureDirectoryExists($directory);

        config()->set('database.connections.'.self::TEMPLATE_CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // Without this SQLite parses a foreign key and then ignores it,
            // so every reference between the eight new tables would be
            // decorative in every test built on this template.
            'foreign_key_constraints' => true,
        ]);

        DB::purge(self::TEMPLATE_CONNECTION);

        $exit = Artisan::call('migrate', [
            '--database' => self::TEMPLATE_CONNECTION,
            '--path' => tenantMigrationPath(),
            '--force' => true,
        ]);

        if ($exit !== 0) {
            throw new RuntimeException('The access fixture could not apply the tenant tree to its template.');
        }

        $connection = DB::connection(self::TEMPLATE_CONNECTION);

        // The kernel fixture's proof that a query reached tenant A's database
        // and not tenant B's is a row of data. It is created once and its
        // value is moved between the two dumps, so each template carries its
        // own tenant's name.
        $connection->statement('create table marker (value varchar(50) not null)');
        $connection->table('marker')->insert(['value' => '']);

        $templates = [];

        foreach (self::TENANTS as $tenant) {
            $path = $directory.DIRECTORY_SEPARATOR.$tenant.'-template.sqlite';

            // VACUUM INTO refuses an existing target, and a stale template
            // from a killed run is exactly the file nobody should believe.
            File::delete($path);

            $connection->table('marker')->update(['value' => $tenant]);
            $connection->statement("vacuum into '".str_replace("'", "''", $path)."'");

            if (! is_file($path)) {
                throw new RuntimeException("The access fixture could not write the [{$tenant}] template.");
            }

            $templates[$tenant] = $path;
        }

        // The templates are copied, not queried, from here on; an open handle
        // on Windows is what makes a copy or a delete fail later.
        DB::purge(self::TEMPLATE_CONNECTION);

        self::$templates = $templates;

        register_shutdown_function(static function () use ($directory): void {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            clearstatcache();

            if (is_dir($directory)) {
                @rmdir($directory);
            }
        });

        return self::$templates;
    }

    /**
     * Load the kernel fixture's file-scope functions.
     *
     * `require_once` rather than relying on another test file having been
     * collected first: a filtered single-file run of any file built on this
     * class must work on its own, and that is exactly the defect FP-164
     * created this shared fixture to fix.
     */
    private static function requireKernelFixture(): void
    {
        require_once __DIR__.'/../../../kernel/tests/Support/tenancy-fixture.php';
    }
}

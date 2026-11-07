<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Http\RequestId;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Contracts\TenantFleet;
use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\MySqlTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\RecordingTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\RefusingTenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Flexipos\Kernel\Tenancy\Secrets\EnvSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\FileSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\FileSecretStore;
use Flexipos\Kernel\Tenancy\Secrets\SchemeSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\SchemeSecretStore;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\WritableSecretStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;

/**
 * Wires the tenancy plumbing and, more importantly, installs the guard that
 * makes "no tenant resolved" a loud failure instead of a silent read.
 *
 * The guarantee is enforced at three levels, because each one alone has a hole:
 *
 * 1. config/database.php's `tenant` stanza holds a sentinel driver and nothing
 *    else, so there is no usable database to connect to at rest (AC-3);
 * 2. the connection factory is decorated with TenantConnectionFactory, which
 *    refuses to *build* a tenant connection unless a tenant is resolved and
 *    TenantConnectionAuthority vouches for the exact stanza it was handed;
 * 3. a per-query callback on the built connection re-checks the context on every
 *    query and before every transaction.
 *
 * Level 3 is the load-bearing one. Levels 1 and 2 only run when a connection is
 * *created*, so a Connection object already cached in
 * DatabaseManager::$connections - or held on some repository's property across a
 * tenant switch or a queue worker's scope reset - would otherwise answer a query
 * with no check at all (AC-16).
 *
 * Level 2 is a decoration of the `db.factory` binding, registered here in
 * register() so that it is in place before anything can resolve `db`:
 * DatabaseManager is handed the factory at construction (MEASURED,
 * DatabaseManager.php:75), so a decoration applied later would never reach it.
 * Because every construction route in the framework funnels into
 * ConnectionFactory::make(), that one decoration is also what attaches level 3,
 * exactly once, to every tenant connection however it was built.
 *
 * FP-160 replaced three attachments with that one, and all three are gone:
 *
 * - the name-keyed connection extension, which DatabaseManager::connectUsing()
 *   and build() never consulted (QA BUG-2);
 * - the ConnectionEstablished listener added to cover them, which Event::fake()
 *   silently disabled (AC-27);
 * - the static WeakMap that de-duplicated level 3 between the two, which one
 *   choke point has nothing to de-duplicate (AC-25).
 *
 * See TenantConnectionFactory for the measurements behind each of those.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    /**
     * VarDumper casters are global, process-wide state; registering them once is
     * enough however many applications a test run boots.
     */
    private static bool $dumpCastersRegistered = false;

    public function register(): void
    {
        // Scoped, not singleton: a queue worker calls forgetScopedInstances()
        // between jobs, which drops the resolved tenant and returns the process
        // to the "no tenant" state whether or not the job left its scope.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(Tenancy::class);
        // Scoped for the same reason, and it matters more here: the authority's
        // records are what make a stanza usable, so a scope reset must take
        // them with it rather than leave a stanza from a dropped scope
        // verifiable.
        $this->app->scoped(TenantConnectionAuthority::class);

        // The migratable-status authority (plan section 18, step 13e). A
        // singleton because it is immutable shipped data, and bound to
        // shipped() rather than to a constructor the container could autowire
        // - the constructor is private, and a binding is the one place a
        // reviewer looks to see which list the process runs on. There is no
        // configuration key behind this and there must never be one: the
        // reconciliation in plan section 18.2 depends on the production value
        // being a compile-time constant.
        $this->app->singleton(TenantStatusPolicy::class, static fn (): TenantStatusPolicy => TenantStatusPolicy::shipped());

        $this->app->singleton(SecretResolver::class, static function (Application $app): SecretResolver {
            // env:// since T-003 (decision D-31); file:// since T-026 (D-6) -
            // the writable driver this task builds. Every other scheme
            // docs/database.md section 4.4 lists is still refused by
            // SchemeSecretResolver until the task that needs it adds a driver.
            $config = $app->make(ConfigRepository::class);

            return new SchemeSecretResolver([
                EnvSecretResolver::SCHEME => new EnvSecretResolver($app),
                FileSecretResolver::SCHEME => new FileSecretResolver(
                    (string) $config->get('tenancy.secrets.file_root')
                ),
            ]);
        });

        // T-026 D-6: the write half. env:// is deliberately not registered
        // here - it stays read-only (D-31) - so a put() against it is
        // refused as an unregistered scheme, not by a special case.
        $this->app->singleton(WritableSecretStore::class, static function (Application $app): WritableSecretStore {
            $config = $app->make(ConfigRepository::class);

            return new SchemeSecretStore([
                FileSecretResolver::SCHEME => new FileSecretStore(
                    (string) $config->get('tenancy.secrets.file_root')
                ),
            ]);
        });

        // T-026 Ruling 4. The default refuses - "no provisioner configured" -
        // and the real MySQL/MariaDB implementation is bound only when a
        // deployment sets tenancy.provisioning.driver=mysql explicitly.
        // phpunit.xml never sets it, so a test that forgets to bind the
        // recording stand-in fails closed instead of reaching a live server
        // (ENGINE_HOLD).
        $this->app->singleton(TenantDatabaseProvisioner::class, static function (Application $app): TenantDatabaseProvisioner {
            $config = $app->make(ConfigRepository::class);
            $driver = (string) $config->get('tenancy.provisioning.driver', 'refusing');

            return match ($driver) {
                'mysql' => $app->make(MySqlTenantDatabaseProvisioner::class),
                'recording' => $app->make(RecordingTenantDatabaseProvisioner::class),
                default => $app->make(RefusingTenantDatabaseProvisioner::class),
            };
        });

        $this->app->singleton(
            TenantDatabaseRepository::class,
            static function (Application $app): TenantDatabaseRepository {
                $config = $app->make(ConfigRepository::class);

                // The local stand-in: a configuration array and no query. It is
                // what T-003 shipped and what T-003's and T-007's fixtures
                // still use, so those proofs need no master schema. Selecting
                // it outside local development is refused in MappingSource,
                // not here, so the runner's binding below cannot drift from
                // this one (review S-4).
                if (MappingSource::isConfigStandIn($config, $app)) {
                    /** @var array<string, mixed> $databases */
                    $databases = (array) $config->get('tenancy.databases', []);

                    return InMemoryTenantDatabaseRepository::fromConfig($databases);
                }

                // The shipped source (FP-205): one joined read of the master
                // schema, behind the 300-second window. Built here rather than
                // bound separately because the decorator is not optional - a
                // request that resolved the bare reader would read the master
                // on every query that opens a tenant connection.
                return new CachingTenantDatabaseRepository(
                    $app->make(MasterTenantDatabaseRepository::class),
                    $app->make(CacheFactory::class)->store(),
                    (int) $config->get('tenancy.mapping.ttl', 300),
                );
            }
        );

        // The runner's mapping source, selected by the same key as the serving
        // one so a fixture gets a matching pair. It is a separate binding, not
        // a second method on the serving repository: only one of the two may
        // ever be reachable from a request (AC-18).
        $this->app->singleton(
            TenantMigrationTargetRepository::class,
            static function (Application $app): TenantMigrationTargetRepository {
                $config = $app->make(ConfigRepository::class);

                if (MappingSource::isConfigStandIn($config, $app)) {
                    /** @var array<string, mixed> $targets */
                    $targets = (array) $config->get('tenancy.migration_targets', []);

                    return InMemoryTenantMigrationTargetRepository::fromConfig($targets);
                }

                return $app->make(MasterTenantMigrationTargetRepository::class);
            }
        );

        // T-017 Ruling 1. A singleton holding a closure, never a captured
        // request, for ClientIp's reason (packages/access): the id is read
        // at write time, so one container serving many requests reports
        // each request's own id. A test that needs a chosen id binds
        // RequestId::fixed() over this.
        $this->app->singleton(RequestId::class, static fn ($app): RequestId => RequestId::fromRequests($app));

        // T-017 Ruling 6. The same MappingSource switch as the serving
        // repository above, so a fixture that got one of each would be
        // proving nothing about either.
        $this->app->singleton(
            TenantFleet::class,
            static function (Application $app): TenantFleet {
                $config = $app->make(ConfigRepository::class);

                if (MappingSource::isConfigStandIn($config, $app)) {
                    return new InMemoryTenantFleet($config);
                }

                return new MasterTenantFleet($app->make(DatabaseManager::class), $config);
            }
        );

        $this->registerCredentialDumpCasters();
        $this->registerTenantConnectionChokePoint();
    }

    /**
     * Decorate the connection factory, which is levels 2 and 3 in one place.
     *
     * extend() rather than a fresh singleton binding, so the factory this wraps
     * is whatever the container would otherwise have produced - including
     * another package's own decoration. Nothing here resolves `db`,
     * `db.factory` or any connection: a request that never touches a database
     * still never instantiates the manager.
     */
    private function registerTenantConnectionChokePoint(): void
    {
        $this->app->extend(
            'db.factory',
            static function (ConnectionFactory $factory, Container $container): ConnectionFactory {
                return new TenantConnectionFactory($container, $factory);
            }
        );
    }

    /**
     * Belt and braces for cloners built *after* this provider registers.
     *
     * **This is deliberately not what keeps a credential out of a dump.** The
     * framework installs its dumper during its own provider registration and
     * captures one VarCloner in the handler closure (CliDumper::register()); a
     * cloner copies AbstractCloner::$defaultCasters at construction, so casters
     * added here - later, from an application provider - provably never reach
     * the handler dd() and dump() actually use (review R-1). Registration order
     * cannot be won.
     *
     * What carries the guarantee is that Secret and ConnectionPassword hold no
     * state at all: the value lives in CredentialValues, a WeakMap keyed by the
     * instance, so `(array) $object` is empty and there is nothing for any
     * cloner to render but the virtual key __debugInfo() contributes. That works
     * whatever casters a given cloner happens to carry.
     *
     * This registration still earns its place for every cloner constructed after
     * boot - a package's own, a test's, a future tool's - which does copy these.
     */
    private function registerCredentialDumpCasters(): void
    {
        if (self::$dumpCastersRegistered) {
            return;
        }

        $redact = static fn (): array => [Caster::PREFIX_VIRTUAL.'value' => TenancyException::REDACTED];

        AbstractCloner::addDefaultCasters([
            Secret::class => $redact,
            ConnectionPassword::class => $redact,
        ]);

        self::$dumpCastersRegistered = true;
    }
}

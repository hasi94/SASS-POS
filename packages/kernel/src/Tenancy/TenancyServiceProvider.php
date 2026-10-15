<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Flexipos\Kernel\Tenancy\Secrets\EnvSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\SchemeSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connectors\ConnectionFactory;
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

        $this->app->singleton(SecretResolver::class, static function (Application $app): SecretResolver {
            // env:// only in T-003 (decision D-31). Every other scheme
            // docs/database.md section 4.4 lists is refused by
            // SchemeSecretResolver until the task that needs it adds a driver.
            return new SchemeSecretResolver([
                EnvSecretResolver::SCHEME => new EnvSecretResolver($app),
            ]);
        });

        $this->app->singleton(
            TenantDatabaseRepository::class,
            static function (Application $app): TenantDatabaseRepository {
                $config = $app->make(ConfigRepository::class);

                // The local stand-in: a configuration array and no query. It is
                // what T-003 shipped and what T-003's and T-007's fixtures
                // still use, so those proofs need no master schema.
                if ((string) $config->get('tenancy.mapping.source', 'master') === 'config') {
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

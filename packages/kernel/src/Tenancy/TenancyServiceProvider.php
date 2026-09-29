<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Flexipos\Kernel\Tenancy\Secrets\EnvSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\SchemeSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;
use SensitiveParameter;
use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;
use WeakMap;

/**
 * Wires the tenancy plumbing and, more importantly, installs the guard that
 * makes "no tenant resolved" a loud failure instead of a silent read.
 *
 * The guarantee is enforced at three levels, because each one alone has a hole:
 *
 * 1. config/database.php's `tenant` stanza holds a sentinel driver and nothing
 *    else, so there is no usable database to connect to at rest (AC-3);
 * 2. the connection extension below refuses to *build* the connection unless a
 *    tenant is resolved and the configuration it was handed belongs to that
 *    tenant (AC-4);
 * 3. a per-query callback on the built connection re-checks the context on every
 *    query and before every transaction.
 *
 * Level 3 is the load-bearing one. Levels 1 and 2 only run when a connection is
 * *created*, so a Connection object already cached in
 * DatabaseManager::$connections - or held on some repository's property across a
 * tenant switch or a queue worker's scope reset - would otherwise answer a query
 * with no check at all (AC-16).
 *
 * Level 2 is keyed by connection *name* and is therefore only consulted by
 * DatabaseManager::makeConnection(). DatabaseManager::connectUsing() - and
 * build(), which delegates to it - call the connection factory **directly**, so
 * neither level 2 nor level 3 would ever run for a connection made that way
 * (QA BUG-2: measured serving tenant B's rows inside tenant A's scope with the
 * context still reporting A). Both of those do dispatch ConnectionEstablished,
 * so a listener on that event makes level 3 unconditional across every
 * construction route and refuses outright when a `tenant` connection was built
 * by something other than Tenancy. That listener, not the extension, is what
 * makes AC-15's "no second working path" true.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Connection objects level 3 is already attached to.
     *
     * A connection built the ordinary way is guarded by the extension and then
     * seen again by the ConnectionEstablished listener; without this it would
     * carry two identical callbacks. Weak, so a purged connection is collected
     * normally, and static, because it is keyed by object identity and must
     * outlive the container that happened to build the connection.
     *
     * @var WeakMap<Connection, true>|null
     */
    private static ?WeakMap $guardedConnections = null;

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
                /** @var array<string, mixed> $databases */
                $databases = (array) $app->make(ConfigRepository::class)->get('tenancy.databases', []);

                return InMemoryTenantDatabaseRepository::fromConfig($databases);
            }
        );

        $this->registerCredentialDumpCasters();

        // Registered only once something actually resolves the database manager,
        // so a process that never touches a database - /health/live, for one -
        // still never instantiates it.
        $this->callAfterResolving('db', function (DatabaseManager $manager): void {
            $this->registerTenantConnectionGuard($manager);
        });

        // Registered on the event dispatcher rather than inside the callback
        // above, because this has to be listening before *anything* builds a
        // connection - including the routes that never resolve the manager
        // through DB::connection(). Listening costs nothing and touches no
        // database.
        $this->registerTenantConnectionEstablishedGuard();
    }

    /**
     * Make level 3 unconditional: attach it to every `tenant` connection however
     * it was constructed, and refuse one that Tenancy did not configure.
     *
     * DatabaseManager::connectUsing() dispatches this event *before* caching the
     * connection under its name, so throwing here also stops an unguarded object
     * being installed as `tenant` for the rest of the process.
     */
    private function registerTenantConnectionEstablishedGuard(): void
    {
        $app = $this->app;

        $this->app->make('events')->listen(
            ConnectionEstablished::class,
            static function (ConnectionEstablished $event) use ($app): void {
                $name = (string) $app->make(ConfigRepository::class)->get('tenancy.connection', 'tenant');

                if ($event->connectionName !== $name) {
                    return;
                }

                $connection = $event->connection;
                $configuredTenant = $connection->getConfig(Tenancy::TENANT_MARKER);

                // No marker means this connection did not come from Tenancy, so
                // there is no tenant identity to check a query against and no
                // way to tell whose database it points at. Refuse it outright
                // rather than guarding something unidentifiable.
                //
                // Which refusal matters: saying "no tenant has been resolved"
                // while one plainly has been sends the reader hunting for a
                // missing Tenancy::run() instead of for the code that built this
                // connection behind Tenancy's back (review R-5).
                if (! is_string($configuredTenant) || $configuredTenant === '') {
                    $context = $app->make(TenantContext::class);

                    throw $context->hasTenant()
                        ? TenantConnectionMismatch::forConfiguration($context->tenantId(), null)
                        : NoTenantResolved::forConnection($name);
                }

                self::guardEveryQuery($connection, $app, $name, $configuredTenant);
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

    /**
     * Install the guard as a connection *extension*, keyed by connection name.
     *
     * DatabaseManager::makeConnection() consults name-keyed extensions before
     * the driver-keyed ones and before the factory, which makes this the single
     * choke point every tenant connection has to pass through - including the
     * ones Eloquent resolves for a model.
     */
    private function registerTenantConnectionGuard(DatabaseManager $manager): void
    {
        $app = $this->app;
        $config = $app->make(ConfigRepository::class);
        $connectionName = (string) $config->get('tenancy.connection', 'tenant');

        $manager->extend(
            $connectionName,
            /**
             * $config is the stanza Tenancy wrote, so it holds the resolved
             * credential. SensitiveParameter keeps it out of getTrace(), which
             * matters because this closure is where the refusals below are
             * raised and Symfony's fallback error renderer prints argument
             * *values*, not just their types.
             *
             * @param  array<string, mixed>  $config
             */
            static function (#[SensitiveParameter] array $config, string $name) use ($app): Connection {
                $settings = $app->make(ConfigRepository::class);
                $context = $app->make(TenantContext::class);

                $driver = is_string($config['driver'] ?? null) ? (string) $config['driver'] : '';
                $placeholder = (string) $settings->get('tenancy.placeholder_driver', 'flexipos-no-tenant');

                // Level 2. The placeholder driver means Tenancy has not written
                // this stanza, and no resolved tenant means there is nothing it
                // could legitimately have written.
                if ($driver === $placeholder || ! $context->hasTenant()) {
                    throw NoTenantResolved::forConnection($name);
                }

                $configuredTenant = is_string($config[Tenancy::TENANT_MARKER] ?? null)
                    ? (string) $config[Tenancy::TENANT_MARKER]
                    : null;

                if ($configuredTenant !== $context->tenantId()) {
                    throw TenantConnectionMismatch::forConfiguration($context->tenantId(), $configuredTenant);
                }

                $allowed = [];

                foreach ((array) $settings->get('tenancy.allowed_drivers', ['mysql', 'mariadb']) as $allowedDriver) {
                    $allowed[] = (string) $allowedDriver;
                }

                if (! in_array($driver, $allowed, true)) {
                    throw TenantConnectionMismatch::forDisallowedDriver($configuredTenant, $driver, $allowed);
                }

                // 'db.factory' is the only binding DatabaseServiceProvider
                // registers for the factory; there is no class alias for it, so
                // the string is the binding to ask for.
                $factory = $app->make('db.factory');

                return self::guardEveryQuery($factory->make($config, $name), $app, $name, $configuredTenant);
            }
        );
    }

    /**
     * Level 3: re-check the resolved tenant on every query and before every
     * transaction on this connection object.
     *
     * The context is resolved from the container *inside* the callback, never
     * captured, so a container scope reset (what a queue worker does between
     * jobs) is seen here rather than remembered.
     */
    private static function guardEveryQuery(
        Connection $connection,
        Application $app,
        string $name,
        string $tenantId
    ): Connection {
        $guarded = self::$guardedConnections ??= new WeakMap;

        // The ordinary route reaches this twice - once from the extension, once
        // from the ConnectionEstablished listener - and two identical callbacks
        // would be harmless but misleading to anyone reading a stack trace.
        if (isset($guarded[$connection])) {
            return $connection;
        }

        $guarded[$connection] = true;

        $guard = static function () use ($app, $name, $tenantId): void {
            $context = $app->make(TenantContext::class);

            if (! $context->hasTenant()) {
                throw NoTenantResolved::forConnection($name);
            }

            if ($context->tenantId() !== $tenantId) {
                throw TenantConnectionMismatch::forStaleConnection($tenantId, $context->tenantId());
            }
        };

        $connection->beforeExecuting(static function () use ($guard): void {
            $guard();
        });

        $connection->beforeStartingTransaction(static function () use ($guard): void {
            $guard();
        });

        return $connection;
    }
}

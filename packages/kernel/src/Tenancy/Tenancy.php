<?php

namespace Flexipos\Kernel\Tenancy;

use Closure;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Exceptions\InvalidTenantIdentifier;
use Flexipos\Kernel\Tenancy\Exceptions\TenancyException;
use Flexipos\Kernel\Tenancy\Exceptions\TenantAlreadyResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionFailed;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotActive;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotMapped;
use Flexipos\Kernel\Tenancy\Secrets\ConnectionPassword;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * The one place a tenant database connection is ever configured (AC-15).
 *
 * `run()` is the API every caller should use; `enter()`/`leave()` exist for the
 * cases that genuinely cannot wrap a closure. Nothing else in the codebase may
 * write `database.connections.tenant`, and the guard in TenancyServiceProvider
 * refuses a configuration this class did not write.
 *
 * Teardown is config()->set() of the placeholder plus DB::purge(), never
 * DatabaseManager::build() or connectUsing(). That is not a style choice:
 * build() stores the full configuration - password included - in the private
 * DatabaseManager::$dynamicConnectionConfigurations array, configuration()
 * prefers that array over config('database.connections.*'), and purge() never
 * clears it, with no public API that can. After purging, asking for the tenant
 * connection would silently rebuild the *previous* tenant's connection from
 * state no code can see or reset. config()->set() + purge() - what
 * docs/database.md section 4.4 specifies - retains nothing.
 *
 * Scoped, not a plain singleton, so it is recreated together with the
 * TenantContext it drives when a queue worker resets the container scope.
 */
final class Tenancy
{
    /**
     * The key the connection array carries so both guards can check that the
     * configuration they were handed belongs to the tenant the context reports.
     */
    public const TENANT_MARKER = 'flexipos_tenant';

    /**
     * Nesting depth of re-entrant enter() calls for the *same* tenant. Zero
     * means "one scope", so the next leave() tears the connection down.
     */
    private int $depth = 0;

    public function __construct(
        private readonly Application $app,
        private readonly TenantContext $context,
        private readonly TenantDatabaseRepository $repository,
        private readonly SecretResolver $secrets,
    ) {}

    /**
     * Resolve a tenant, run the callback, and always tear the connection down
     * again - including when the callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $tenantId, Closure $callback): mixed
    {
        $this->enter($tenantId);

        try {
            return $callback();
        } finally {
            $this->leave();
        }
    }

    /**
     * The current context. Never null: ask hasTenant() before the getters.
     */
    public function current(): TenantContext
    {
        return $this->context;
    }

    /**
     * Resolve a tenant and configure the tenant connection for it.
     *
     * The order of the checks below is itself an acceptance criterion: validate
     * the input, then read the mapping, then check the status, and only then
     * touch any state or attempt a connection. Steps 1 to 6 mutate nothing, so
     * every refusal leaves database.connections.tenant exactly as it was
     * (AC-9/AC-10's "no partial overwrite").
     *
     * @throws InvalidTenantIdentifier the identifier is empty or whitespace (AC-11)
     * @throws TenantAlreadyResolved a different tenant is already resolved
     * @throws TenantDatabaseNotMapped no mapping record exists (AC-9)
     * @throws TenantDatabaseNotActive the mapping status forbids access (AC-10)
     * @throws TenantConnectionMismatch the mapping asks for a disallowed driver
     * @throws TenantConnectionFailed the database could not be opened (AC-12)
     */
    public function enter(string $tenantId): void
    {
        // 1. Input validation, before the mapping source is touched at all.
        $identifier = trim($tenantId);

        if ($identifier === '') {
            throw InvalidTenantIdentifier::emptyIdentifier();
        }

        // 2. Re-entrancy: the same tenant nests, a different one is refused.
        if ($this->context->hasTenant()) {
            if ($this->context->tenantId() === $identifier) {
                $this->depth++;

                return;
            }

            throw TenantAlreadyResolved::forTenant($this->context->tenantId(), $identifier);
        }

        // 3. The mapping.
        $database = $this->repository->find($identifier);

        if (! $database instanceof TenantDatabase) {
            throw TenantDatabaseNotMapped::forTenant($identifier);
        }

        // 4. The status gate (decision D-26: only `active` connects).
        if (! $database->isActive()) {
            throw TenantDatabaseNotActive::forTenant($identifier, $database->status);
        }

        // 5. The driver whitelist, so a mapping row cannot smuggle a driver the
        //    platform never intended to talk to into the process.
        $allowed = $this->allowedDrivers();

        if (! in_array($database->driver, $allowed, true)) {
            throw TenantConnectionMismatch::forDisallowedDriver($identifier, $database->driver, $allowed);
        }

        // 6. Dereference the credential. Never read from the mapping record:
        //    the record has no password property to read (AC-8).
        $secret = $this->secrets->resolve($database->credentialRef);

        // 7. Only now is any state touched. Tear down whatever was there first -
        //    including a stanza or a cached connection left behind by a dropped
        //    container scope - then write the new one wholesale.
        $this->resetTenantConnection();
        $this->writeTenantConnection($database, $secret);
        $this->context->resolved($identifier, $database);

        // 8. Connect eagerly, inside the try, so a driver-level failure is
        //    caught here instead of surfacing lazily from somewhere whose trace
        //    carries the connector's $config argument.
        try {
            $this->database()->connection($this->connectionName())->getPdo();
        } catch (Throwable $exception) {
            $this->resetTenantConnection();
            $this->context->forget();
            $this->depth = 0;

            // Our own guards refusing here means an invariant broke, not that
            // the database is unreachable; that message is already sanitised.
            if ($exception instanceof TenancyException) {
                throw $exception;
            }

            throw TenantConnectionFailed::forTenant(
                $identifier,
                $database->driver,
                $this->sanitise($exception->getMessage(), $secret)
            );
        }
    }

    /**
     * Return the process to "no tenant resolved" (AC-18, decision D-28).
     *
     * Called from run()'s finally, so it never throws: an open tenant
     * transaction is rolled back and logged rather than reported, because
     * throwing here would mask whatever the callback failed with. The rollback
     * is also what clears the pending records DatabaseTransactionsManager keys
     * by connection *name* - without it, tenant A's afterCommit callbacks could
     * fire on tenant B's commit.
     */
    public function leave(): void
    {
        if ($this->depth > 0) {
            $this->depth--;

            return;
        }

        $this->resetTenantConnection();
        $this->context->forget();
    }

    /**
     * Purge the tenant connection and put the placeholder stanza back.
     *
     * Idempotent, and a genuine no-op when there is nothing to undo, so it is
     * safe to call on the way in as well as on the way out.
     */
    private function resetTenantConnection(): void
    {
        $database = $this->database();
        $key = 'database.connections.'.$this->connectionName();

        foreach ($this->cachedTenantConnectionNames() as $cached) {
            $this->rollBackOpenTenantTransaction($cached);

            // purge(), not disconnect(): disconnect() only nulls the PDO and
            // leaves the object in DatabaseManager::$connections, where
            // reconnectIfMissingConnection() would silently reopen it.
            $database->purge($cached);
        }

        if ($this->config()->get($key) !== $this->placeholder()) {
            $this->config()->set($key, $this->placeholder());
        }
    }

    /**
     * Every name the manager currently caches a tenant connection under.
     *
     * DatabaseManager caches a read/write-suffixed connection under that exact
     * key ("tenant::read"), and purge() unsets only the key it is given - so
     * purging the base name alone left a live handle on the previous shop's
     * database inside the manager itself after the scope ended (review R-2).
     * The per-query guard refuses queries on it, but an open handle that
     * outlives its tenant scope is not something to rely on a second line of
     * defence for, and it becomes a real leak the day FP-203 adds read replicas.
     *
     * @return list<string>
     */
    private function cachedTenantConnectionNames(): array
    {
        $name = $this->connectionName();
        $names = [];

        foreach (array_keys($this->database()->getConnections()) as $cached) {
            $cached = (string) $cached;

            if ($cached === $name || str_starts_with($cached, $name.'::')) {
                $names[] = $cached;
            }
        }

        return $names;
    }

    private function rollBackOpenTenantTransaction(string $name): void
    {
        $connection = $this->database()->connection($name);
        $level = $connection->transactionLevel();

        if ($level < 1) {
            return;
        }

        $tenantId = $this->configuredTenantId();

        try {
            $connection->rollBack(0);
        } catch (Throwable $exception) {
            // Swallowed on purpose: see the docblock on leave().
            $this->logger()->warning('Rolling back the open tenant transaction failed while leaving the tenant scope.', [
                'tenant_id' => $tenantId,
                'connection' => $name,
                'transaction_level' => $level,
                'exception' => $exception::class,
            ]);

            return;
        }

        $this->logger()->warning('Left a tenant scope with an open transaction; it was rolled back.', [
            'tenant_id' => $tenantId,
            'connection' => $name,
            'transaction_level' => $level,
        ]);
    }

    /**
     * The only write to database.connections.tenant in the codebase, and the
     * only place a credential value appears.
     *
     * set(), not merge: the stanza is replaced wholesale, so no key or value
     * from a previous tenant can survive as a leftover (AC-17).
     */
    private function writeTenantConnection(TenantDatabase $database, #[SensitiveParameter] Secret $secret): void
    {
        $this->config()->set('database.connections.'.$this->connectionName(), [
            'driver' => $database->driver,
            'host' => $database->host,
            'port' => $database->port,
            'database' => $database->database,
            'username' => $database->username,
            // A ConnectionPassword, not a string: PHP records the arguments of
            // the framework's own call_user_func() into the connection
            // extension, and a flattened trace copies string values verbatim but
            // records an object as ['object', get_debug_type($value)]. The
            // connector coerces it back through __toString() when it builds the
            // PDO. This is the only place a Secret becomes one.
            'password' => new ConnectionPassword($secret->reveal()),
            'charset' => 'utf8mb4',
            // utf8mb4_unicode_ci, not utf8mb4_0900_ai_ci: MariaDB 10.4 does not
            // know the latter (docs/database.md section 14).
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            // Never PDO::ATTR_PERSISTENT: a pooled connection is bound to one
            // tenant's database user and cannot be reused once another tenant is
            // resolved in the same process (docs/database.md section 2, rule 4).
            'options' => [],
            self::TENANT_MARKER => $database->tenantId,
        ]);
    }

    /**
     * Remove the credential from a driver message before it becomes part of an
     * exception nobody can redact afterwards.
     */
    private function sanitise(string $message, #[SensitiveParameter] Secret $secret): string
    {
        $value = $secret->reveal();

        if ($value === '') {
            return $message;
        }

        return str_replace($value, TenancyException::REDACTED, $message);
    }

    /**
     * The at-rest tenant stanza: a sentinel driver, no host, no database, no
     * credentials (AC-3).
     *
     * @return array<string, string>
     */
    private function placeholder(): array
    {
        return [
            'driver' => (string) $this->config()->get('tenancy.placeholder_driver', 'flexipos-no-tenant'),
            'database' => '',
            'prefix' => '',
        ];
    }

    private function configuredTenantId(): string
    {
        $configured = $this->config()->get(
            'database.connections.'.$this->connectionName().'.'.self::TENANT_MARKER
        );

        return is_string($configured) && $configured !== '' ? $configured : 'unknown';
    }

    /**
     * @return list<string>
     */
    private function allowedDrivers(): array
    {
        $allowed = [];

        foreach ((array) $this->config()->get('tenancy.allowed_drivers', ['mysql', 'mariadb']) as $driver) {
            $allowed[] = (string) $driver;
        }

        return $allowed;
    }

    private function connectionName(): string
    {
        return (string) $this->config()->get('tenancy.connection', 'tenant');
    }

    private function config(): ConfigRepository
    {
        return $this->app->make(ConfigRepository::class);
    }

    private function database(): DatabaseManager
    {
        return $this->app->make(DatabaseManager::class);
    }

    private function logger(): LoggerInterface
    {
        return $this->app->make(LoggerInterface::class);
    }
}

<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\Kernel\Tenancy\Exceptions\TenantConnectionMismatch;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use SensitiveParameter;

/**
 * The single place a `tenant` Connection object comes into existence (FP-160).
 *
 * MEASURED in laravel/framework v12.69.2: every construction route the spec
 * lists funnels into ConnectionFactory::make(). DatabaseManager::connection()
 * reaches it through makeConnection(); connectUsing() calls
 * `$this->factory->make()` directly; build() delegates to connectUsing();
 * Eloquent resolves through the manager like anything else; a reconnect after
 * purge() rebuilds through makeConnection() again; and a read/write-split
 * stanza is make()'s own createReadWriteConnection() branch. Decorating the
 * factory therefore covers all of them with no event and no second attachment
 * point.
 *
 * What it checks there is TenantConnectionAuthority::verify(), which is the one
 * question asked about a stanza anywhere in the codebase (FP-161). The stanza
 * carries no tenant id to compare against the context - comparing one only ever
 * proved that the writer knew which shop was resolved, which every forger does -
 * so the tenant id the per-query guard pins comes back from the authority's
 * private record instead.
 *
 * What this replaced, and why none of it survived:
 *
 * - a name-keyed `DB::extend('tenant', ...)` connection extension. Keyed by
 *   name, so DatabaseManager::makeConnection() was the only caller that ever
 *   consulted it; connectUsing() and build() went straight to the factory and
 *   were invisible to it (QA BUG-2, measured serving tenant B's rows inside
 *   tenant A's scope);
 * - a ConnectionEstablished listener, added to cover those two routes. It did -
 *   until a test called Event::fake(), which swaps the `events` binding the
 *   manager resolves per dispatch and silently disabled a security guard
 *   (AC-27, measured returning tenant A's own row inside tenant B's scope);
 * - a static WeakMap<Connection, true> that existed only to stop the two
 *   attachments above both guarding the connections they both saw. One choke
 *   point has nothing to de-duplicate, so the map, and the process-wide
 *   retention it implied, are gone (AC-25, AC-26).
 *
 * The decoration must still *extend* ConnectionFactory, because
 * DatabaseManager::__construct type-hints it (MEASURED). It must not call
 * parent::make(): the connection is built by the factory this one replaced, so
 * any other extender of 'db.factory' stays composed instead of being silently
 * discarded.
 *
 * Known residual (R7): DatabaseManager::makeConnection() consults
 * `$extensions[$name]` in its first branch, *before* it reaches the factory, so
 * anything registering a name-keyed extension for the tenant connection would
 * bypass this class. Nothing does, and TenantConnectionChokePointTest asserts
 * that as a standing invariant. Cited by symbol: the line was :195 when this
 * was written and is :192 on v12.69.2, and a line number no test checks is
 * decoration - the behaviour is pinned by TenancyFrameworkContractTest and the
 * absence of such an extension by the choke-point test.
 */
final class TenantConnectionFactory extends ConnectionFactory
{
    /**
     * @param  ConnectionFactory  $inner  the factory this one decorates - the
     *                                    one the container would otherwise have
     *                                    handed to DatabaseManager
     */
    public function __construct(
        Container $container,
        private readonly ConnectionFactory $inner,
    ) {
        parent::__construct($container);
    }

    /**
     * Build a connection, refusing outright when the one asked for is a tenant
     * connection that no resolved tenant can account for.
     *
     * $config is the stanza Tenancy wrote, so it holds the resolved credential.
     * SensitiveParameter keeps it out of getTrace(), which matters because the
     * refusals below are raised from this frame and Symfony's fallback error
     * renderer prints argument *values*, not just their types.
     *
     * @param  array<string, mixed>  $config
     * @param  string|null  $name
     *
     * @throws NoTenantResolved the stanza is the at-rest placeholder, or no
     *                          tenant is resolved at all
     * @throws TenantConnectionMismatch a tenant is resolved but the stanza is
     *                                  not one Tenancy wrote for it, or it asks
     *                                  for a driver the platform forbids
     */
    public function make(#[SensitiveParameter] array $config, $name = null): Connection
    {
        $settings = $this->settings();
        $connectionName = (string) $settings->get('tenancy.connection', 'tenant');

        // The authority is resolved per call, never captured: it is scoped and
        // this factory is a singleton, so a captured one would outlive the
        // scope whose records it holds.
        $authority = $this->authority();

        // Two halves, and both are load bearing. The name catches every route
        // that asks the manager for the tenant connection; the authenticator
        // catches `make($legitimateStanza, null)`, which would otherwise
        // produce an unguarded tenant connection with no name to recognise it
        // by. The read/write suffixes are part of the same connection as far as
        // DatabaseManager::parseConnectionName() is concerned, and
        // Tenancy::cachedTenantConnectionNames() already treats them that way.
        if (! $this->isTenantConnectionName($name, $connectionName) && ! $authority->carries($config)) {
            return $this->inner->make($config, $name);
        }

        $context = $this->context();
        $refusalName = is_string($name) && $name !== '' ? $name : $connectionName;

        $driver = is_string($config['driver'] ?? null) ? (string) $config['driver'] : '';
        $placeholder = (string) $settings->get('tenancy.placeholder_driver', 'flexipos-no-tenant');

        // The placeholder driver means Tenancy has not written this stanza, and
        // no resolved tenant means there is nothing it could legitimately have
        // written.
        if ($driver === $placeholder || ! $context->hasTenant()) {
            throw NoTenantResolved::forConnection($refusalName);
        }

        // The one question, and the only place it is asked: does the authority
        // vouch for this exact stanza, and for the tenant that is resolved. The
        // tenant id comes back from the authority's private record rather than
        // out of the stanza, so the value the per-query guard pins is one no
        // caller supplied.
        $tenantId = $authority->verify($config);

        // Which refusal matters (review R-5): saying "no tenant has been
        // resolved" while one plainly has been sends the reader hunting for a
        // missing Tenancy::run() instead of for the code that wrote this stanza
        // behind Tenancy's back.
        if ($tenantId === null || $tenantId !== $context->tenantId()) {
            throw TenantConnectionMismatch::forUnauthorisedConfiguration($context->tenantId());
        }

        $allowed = [];

        foreach ((array) $settings->get('tenancy.allowed_drivers', ['mysql', 'mariadb']) as $allowedDriver) {
            $allowed[] = (string) $allowedDriver;
        }

        if (! in_array($driver, $allowed, true)) {
            throw TenantConnectionMismatch::forDisallowedDriver($tenantId, $driver, $allowed);
        }

        return $this->guardEveryQuery($this->inner->make($config, $name), $refusalName, $tenantId);
    }

    /**
     * Re-check the resolved tenant on every query and before every transaction
     * on this connection object.
     *
     * This is the load-bearing half of the guarantee, not the construction
     * checks above: DB::purge() drops the manager's reference, but any object
     * held on a property or closed over still reconnects through
     * Connection::reconnectIfMissingConnection() and would answer with whatever
     * tenant is configured by then. The manager's reconnector is
     * `fn ($connection) => $connection->setPdo($this->reconnect(...)->getRawPdo())`,
     * so the repointing is silent.
     *
     * The context is resolved from the container *inside* the callback, never
     * captured, so a container scope reset - what a queue worker does between
     * jobs - is seen here rather than remembered.
     *
     * One attachment, unconditionally, because there is now one place that
     * builds the connection. Nothing records which objects have been guarded.
     */
    private function guardEveryQuery(Connection $connection, string $name, string $tenantId): Connection
    {
        // The container, not $this: a per-query callback that captured the
        // factory would make every guarded Connection hold a reference to it,
        // and the thing this guard is about is objects outliving their scope.
        $container = $this->container;

        $guard = static function () use ($container, $name, $tenantId): void {
            /** @var TenantContext $context */
            $context = $container->make(TenantContext::class);

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

    private function isTenantConnectionName(mixed $name, string $connectionName): bool
    {
        if (! is_string($name)) {
            return false;
        }

        return $name === $connectionName || str_starts_with($name, $connectionName.'::');
    }

    private function authority(): TenantConnectionAuthority
    {
        /** @var TenantConnectionAuthority $authority */
        $authority = $this->container->make(TenantConnectionAuthority::class);

        return $authority;
    }

    private function context(): TenantContext
    {
        /** @var TenantContext $context */
        $context = $this->container->make(TenantContext::class);

        return $context;
    }

    private function settings(): ConfigRepository
    {
        /** @var ConfigRepository $settings */
        $settings = $this->container->make(ConfigRepository::class);

        return $settings;
    }
}

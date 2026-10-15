<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Exceptions\MappingSourceNotPermitted;
use Flexipos\Kernel\Tenancy\Secrets\EnvSecretResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Which mapping source this process runs on, and the one it may not run on
 * outside local development (review S-4).
 *
 * `master` is the shipped source. `config` is the stand-in that answers out of
 * `tenancy.databases` / `tenancy.migration_targets` and queries nothing; the
 * T-003 and T-007 fixtures use it so their proofs need no master schema. The
 * key behind it is `TENANCY_MAPPING_SOURCE`, which means a deployment can
 * select the stand-in with an environment variable and serve real tenants from
 * a file - no status column, no schema version, no licence, and nothing that
 * notices a tenant was suspended.
 *
 * **Why this is not already covered downstream.** The stand-in's mappings
 * carry a credential *reference*, and today every reference the shipped
 * container can resolve is `env://` - the one scheme SchemeSecretResolver is
 * built with - which {@see EnvSecretResolver} refuses outside local
 * development. So a production process on the stand-in fails anyway. But it
 * fails for a reason that is about the credential scheme, and that scheme
 * registry is explicitly waiting to be widened: its own comment says "refused
 * by SchemeSecretResolver until the task that needs it adds a driver". The
 * first task that adds `file://` or `vault://` removes the block without ever
 * reading this file. The refusal belongs where the source is chosen.
 *
 * **Allow-list, not a deny-list**, for the reason review R-6 gave against
 * matching production by name: a deployment called `prod`, `live` or any house
 * name would walk straight through a `!== 'production'` test. The list is
 * {@see EnvSecretResolver::LOCAL_ENVIRONMENTS} itself rather than a second
 * copy of the same names, because both guards are answering the same question
 * - is this process local development - and two copies that drift turn one of
 * them into a hole.
 */
final class MappingSource
{
    /**
     * The configuration key that selects the source.
     */
    public const KEY = 'tenancy.mapping.source';

    /**
     * The shipped source: the master schema, behind the cache window.
     */
    public const MASTER = 'master';

    /**
     * The local stand-in: a configuration array and no query.
     */
    public const CONFIG = 'config';

    /**
     * Is this process on the configuration stand-in?
     *
     * Asked by both repository bindings, so the refusal is written once and
     * neither binding can be widened without the other. The serving mapping
     * and the runner's mapping deliberately read the same key: a fixture that
     * got one of each would be proving nothing about either.
     *
     * @throws MappingSourceNotPermitted when the stand-in is selected outside
     *                                   local development
     */
    public static function isConfigStandIn(ConfigRepository $config, Application $app): bool
    {
        if ((string) $config->get(self::KEY, self::MASTER) !== self::CONFIG) {
            return false;
        }

        if (! $app->environment(EnvSecretResolver::LOCAL_ENVIRONMENTS)) {
            throw MappingSourceNotPermitted::configStandInOutsideLocal(
                (string) $app->environment(),
                self::KEY,
                implode(', ', EnvSecretResolver::LOCAL_ENVIRONMENTS)
            );
        }

        return true;
    }
}

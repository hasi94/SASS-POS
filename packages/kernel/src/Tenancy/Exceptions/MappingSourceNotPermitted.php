<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * The configured mapping source is not one this process may use.
 *
 * There is one such source: the configuration stand-in, which answers "where
 * does this tenant's database live" out of `tenancy.databases` and
 * `tenancy.migration_targets` and queries nothing. It exists so the T-003 and
 * T-007 fixtures need no master schema. A deployment that selects it is
 * serving real tenants from a file an operator can edit, with none of the
 * master's status, version or licence columns in the decision - so it is
 * refused rather than trusted.
 *
 * **This is the one refusal in this family with no tenant identifier in its
 * message**, and the exemption is structural rather than an oversight: the
 * source is chosen once for the whole process, when the repository binding is
 * first resolved, which is before any tenant has been named. What the message
 * carries instead is the environment that was refused and the key that
 * selected the source, because those are the two facts an operator needs to
 * act.
 */
final class MappingSourceNotPermitted extends TenancyException
{
    /**
     * The configuration stand-in was selected outside local development.
     */
    public static function configStandInOutsideLocal(string $environment, string $key, string $permitted): self
    {
        return new self(sprintf(
            'The mapping source [config] is the local development stand-in and is refused in the [%s] '.
            'environment; it is permitted only in [%s]. Set [%s] to [master] so tenant databases are read '.
            'from the master schema.',
            $environment,
            $permitted,
            $key
        ));
    }
}

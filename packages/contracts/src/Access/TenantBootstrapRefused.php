<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

use RuntimeException;

/**
 * The first-owner grant (D-23) refused its own precondition (T-026 Ruling 5).
 *
 * **Published here, not in `packages/access`'s own exception hierarchy.**
 * `TenantAccessBootstrap` is the one door `packages/platform`'s `create_owner`
 * step is allowed to reach into `packages/access` through (AC-39); a caller
 * across that boundary that needed to catch an exception living in
 * `packages/access\Exceptions` would be reaching past the contract to catch
 * the implementation's own internals, exactly what the boundary exists to
 * forbid. This class is the one thing the contract itself declares it may
 * throw.
 *
 * It carries no `RefusalReason` of `packages/access`'s own vocabulary: the
 * two causes below are the whole of what this one operation can refuse, and
 * a caller needs only the message, which names the cause in terms.
 */
final class TenantBootstrapRefused extends RuntimeException
{
    /**
     * Another live employee already holds the system `owner` role (D-23's
     * precondition). The grant this call asked for would make a second one.
     */
    public static function ownerAlreadyHeldByAnother(string $message): self
    {
        return new self($message);
    }

    /**
     * No system role keyed `owner` exists yet in this tenant - `seedPermissions()`
     * has not run, or it refused, before `grantOwner()` was reached.
     */
    public static function noOwnerRole(string $message): self
    {
        return new self($message);
    }

    /**
     * Review round 1 W-4: `grantOwner()`'s only guard was "no other live
     * employee holds owner", which is broader than D-23's own one
     * chicken-and-egg case - a live tenant whose real owner was
     * soft-deleted could otherwise have any employee made owner through
     * this one published door. The target must itself be the `OWNER`-coded,
     * not-soft-deleted employee this run created.
     */
    public static function notTheOwnerCodedEmployee(string $message): self
    {
        return new self($message);
    }
}

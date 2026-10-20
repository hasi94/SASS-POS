<?php

declare(strict_types=1);

namespace Flexipos\Access\Exceptions;

use Flexipos\Access\Support\RoleLookup;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Access\RefusalReason;
use RuntimeException;

/**
 * The one exception this package throws when it refuses to do something.
 *
 * **It is not constructible without a cause.** The constructor is private and
 * every way in is a named constructor carrying exactly one
 * {@see RefusalReason}, so there is no shape of this object that means
 * "refused" and nothing more - which is the shape a test asserting that
 * *something* refused cannot tell apart from a defect. Fourteen reasons and
 * several sibling guards share one HTTP status, so the cause has to travel
 * with the refusal rather than beside it.
 *
 * **The reason is the published part, not this class.** A caller across the
 * boundary matches on {@see self::$reason}, which lives in
 * `packages/contracts`; nothing outside this package should be catching the
 * type.
 *
 * Named constructors arrive with the operations that can produce them: a
 * reason with no thrower yet has no constructor here, so the list below is
 * always the set of refusals this package can actually produce.
 */
final class AccessRefused extends RuntimeException
{
    private function __construct(
        public readonly RefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The request itself is malformed (D-26, D-32).
     *
     * Used by the registry for a declaration set that cannot be synced at
     * all: a key declared twice with different attributes. The malformed and
     * over-long cases never reach here, because
     * {@see PermissionDeclaration} refuses them at
     * construction, so such a key cannot exist as a value.
     */
    public static function validation(string $message): self
    {
        return new self(RefusalReason::Validation, $message);
    }

    /**
     * The role belongs to the platform, not to the shop (AC-21, D-12).
     *
     * Thrown only by {@see RoleLookup::requireCustom()},
     * which is the one place `roles.is_system` is read, so the three
     * operations AC-21 names cannot disagree about what a system role is.
     */
    public static function systemRole(string $message): self
    {
        return new self(RefusalReason::SystemRole, $message);
    }

    /**
     * The role is still assigned to somebody (AC-22).
     *
     * Distinct from {@see self::systemRole()} and from `validation` because
     * all three refuse a deletion and only this one is fixed by removing an
     * assignment: a caller told "refused" cannot tell which of the three it
     * met, and the remedy differs for each.
     */
    public static function roleInUse(string $message): self
    {
        return new self(RefusalReason::RoleInUse, $message);
    }
}

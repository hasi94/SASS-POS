<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use InvalidArgumentException;

/**
 * The one authority for "which `employees.status` values may act" (D-15).
 *
 * **Two different kinds of fact live here, and the difference decides their
 * shape.** {@see self::STATUSES} is a *vocabulary*: the six words the column
 * may hold. A word this list does not name is refused at write, and a task
 * adding a seventh is adding a word, not widening anybody's access, so the
 * vocabulary is a constant with no seam. {@see self::ACTING} is an *access
 * decision*: it says who may hold a token, set a PIN and resolve a
 * permission. A set that can be widened is an access decision, so it moves
 * exactly the way `TenantSelectionPolicy`'s does - through a private
 * constructor and one `@internal` named seam, never through configuration.
 *
 * **Why an object and not six literals.** The acting set is read by the
 * resolver (AC-43), by the claim source at mint (D-6), by the status-change
 * slice that bumps `perm_version` when a change crosses the boundary
 * (AC-27), by the PIN slices and by the last-owner safeguard (AC-37). Six
 * copies of `in_array($status, ['active', 'probation'])` is the shape where
 * the day `probation` stops acting, four of them are updated. The proof that
 * these read rather than copy is not a search of their sources - a literal
 * in double quotes survives any search - it is the moved value: hand them a
 * policy over a different set and watch the answers follow. A copy cannot
 * follow a moved value however it is spelled.
 *
 * **No `config()`, no `env()`, and no object through which either could
 * reach this one.** `modules/host/config/access.php` holds the PIN figures
 * and the page sizes and says in its own header why no status list is in it:
 * an access decision an operator can widen from the environment is not an
 * access decision.
 *
 * It is deliberately not a backed enum. An enum's cases are its vocabulary
 * and that part would fit, but the acting set has to be substitutable per
 * instance for the moved-value proof, and an enum has no instances to vary.
 */
final class EmployeeStatus
{
    public const ACTIVE = 'active';

    public const PROBATION = 'probation';

    public const ON_LEAVE = 'on_leave';

    public const SUSPENDED = 'suspended';

    public const RESIGNED = 'resigned';

    public const TERMINATED = 'terminated';

    /**
     * The six words `employees.status` may hold (D-14: any status may be set
     * to any other; there is no transition graph).
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::ACTIVE,
        self::PROBATION,
        self::ON_LEAVE,
        self::SUSPENDED,
        self::RESIGNED,
        self::TERMINATED,
    ];

    /**
     * The shipped acting set (D-15).
     *
     * An employee on leave signing in to ring sales is the surprising case;
     * an owner marked on leave locking themselves out is the recoverable one,
     * and `last_owner` (AC-37) stops the last owner doing it by accident.
     *
     * A constant, not a constructor default: the shipped value must not be
     * reachable through any channel that is not a code change.
     *
     * @var list<string>
     */
    public const ACTING = [self::ACTIVE, self::PROBATION];

    /**
     * @param  list<string>  $actingStatuses
     */
    private function __construct(
        private readonly array $actingStatuses,
    ) {}

    /**
     * The shipped policy, read from the constant.
     *
     * What the container binds. Every production path resolves this class
     * from the container and receives this; nothing in the shipped code calls
     * the method below.
     */
    public static function shipped(): self
    {
        return new self(self::ACTING);
    }

    /**
     * A policy over a different acting set.
     *
     * The seam the moved-value proof uses, and the only way the acting set
     * can differ from the shipped one.
     *
     * @internal Tests and fixtures only. A production call to this method is
     * a widening of an access decision, and it is visible in a diff as one.
     *
     * @param  list<string>  $statuses
     */
    public static function withActingStatuses(array $statuses): self
    {
        foreach ($statuses as $status) {
            if (! in_array($status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Unknown employee status [{$status}].");
            }
        }

        return new self($statuses);
    }

    /**
     * The statuses this policy lets an employee act under, in the order it
     * holds them.
     *
     * Exposed so a refusal or a sweep can quote the authority rather than
     * restate it.
     *
     * @return list<string>
     */
    public function acting(): array
    {
        return $this->actingStatuses;
    }

    /**
     * May an employee holding this status act?
     *
     * A status nobody shipped answers **false**: deny by default is the safe
     * direction, and it means a seventh word arriving in the column through
     * a hand-written row cannot act until somebody decides it should.
     */
    public function isActing(string $status): bool
    {
        return in_array($status, $this->actingStatuses, true);
    }

    /**
     * Is this one of the six words the column may hold?
     *
     * Static, because the vocabulary does not move: a request naming a
     * seventh status is refused `validation` by every policy there is
     * (AC-25).
     */
    public static function exists(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}

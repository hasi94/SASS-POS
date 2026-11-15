<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

/**
 * The closed set of terminal states and what each one allows (T-014 D-15).
 *
 * `pending` (created, never activated) becomes `active` by activation, which
 * is a different slice; `active` and `inactive` move between each other by
 * pausing and resuming; `pending`, `active` and `inactive` can each be
 * revoked; **`revoked` is final** and nothing moves it. Stored as strings,
 * never as a native enum.
 */
final class TerminalStatus
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const REVOKED = 'revoked';

    /** @var list<string> */
    public const ALL = [self::PENDING, self::ACTIVE, self::INACTIVE, self::REVOKED];

    /**
     * The statuses a branch's deactivation and a location's deactivation are
     * blocked by: a terminal that is, or is about to be, in use.
     *
     * @var list<string>
     */
    public const IN_USE = [self::PENDING, self::ACTIVE];
}

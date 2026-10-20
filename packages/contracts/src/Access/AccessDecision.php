<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

use InvalidArgumentException;

/**
 * The answer {@see PermissionResolver} gives: allowed, or refused with a cause.
 *
 * **A refusal is never constructible without a cause.** The constructor is
 * private and the two named constructors are the only way in, so there is no
 * shape of this object carrying `allowed = false` and no reason - which is the
 * shape a test asserting "something refused" cannot tell apart from a defect.
 *
 * **Two levels of cause, and the difference is a disclosure decision (D-26).**
 * {@see self::reason} is what a caller sees. {@see self::detail} is the finer
 * cause - `not_granted`, `explicit_deny`, `out_of_scope` - which is written to
 * `access_log.detail.reason` and **never** to an HTTP body. It is a string
 * from {@see self::DETAILS} rather than a second enum because it is a log
 * field with three values, not a vocabulary other packages decide against.
 */
final class AccessDecision
{
    /** No allowing contribution covers the key and scope asked about. */
    public const DETAIL_NOT_GRANTED = 'not_granted';

    /** A per-person deny covers the key and scope; deny wins (D-8 (a)). */
    public const DETAIL_EXPLICIT_DENY = 'explicit_deny';

    /** Contributions exist, but none of them covers the branch asked about. */
    public const DETAIL_OUT_OF_SCOPE = 'out_of_scope';

    /**
     * The closed set of finer causes.
     *
     * @var list<string>
     */
    public const DETAILS = [
        self::DETAIL_NOT_GRANTED,
        self::DETAIL_EXPLICIT_DENY,
        self::DETAIL_OUT_OF_SCOPE,
    ];

    private function __construct(
        public readonly bool $allowed,
        public readonly ?RefusalReason $reason,
        public readonly ?string $detail,
    ) {}

    /**
     * The employee may do it.
     */
    public static function allow(): self
    {
        return new self(true, null, null);
    }

    /**
     * The employee may not, for this reason.
     *
     * A `$detail` is accepted only beside {@see RefusalReason::NotPermitted}:
     * the three finer causes exist precisely because that one reason covers
     * three different situations, and attaching one to `branch_required` would
     * invent a cause the resolver cannot have meant.
     */
    public static function refuse(RefusalReason $reason, ?string $detail = null): self
    {
        if ($detail !== null && ! in_array($detail, self::DETAILS, true)) {
            throw new InvalidArgumentException("Unknown access decision detail [{$detail}].");
        }

        if ($detail !== null && $reason !== RefusalReason::NotPermitted) {
            throw new InvalidArgumentException(
                'A detail may only accompany ['.RefusalReason::NotPermitted->value."]; [{$reason->value}] was given."
            );
        }

        return new self(false, $reason, $detail);
    }
}

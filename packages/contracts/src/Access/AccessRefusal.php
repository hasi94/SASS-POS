<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

use Throwable;

/**
 * A refusal thrown by the access implementation, matchable from outside it.
 *
 * **It exists because {@see EmployeeClaimSource} already promised it.** That
 * interface says a refusal is thrown carrying
 * {@see RefusalReason::EmployeeUnavailable}, that the reason is the
 * published part, and that "a caller matches on the reason rather than on a
 * type it would have to import across the boundary". A caller cannot match
 * on a reason it has no way to reach: `packages/auth` may depend on
 * `packages/contracts` and deliberately not on `packages/access`, so without
 * a published type the only ways to honour that sentence are catching
 * `\Throwable` and guessing, or widening the boundary. This interface is the
 * third way and the smallest one.
 *
 * **It publishes the reason and nothing else.** No message, no subject, no
 * HTTP status: a caller is entitled to know *which* refusal happened, and
 * every other property of the refusal belongs to the package that threw it.
 * The message in particular is operator-facing and must not be rendered to a
 * caller, which is why it is not here.
 *
 * Implemented by `Flexipos\Access\Exceptions\AccessRefused`. Nothing outside
 * that package catches the concrete class.
 */
interface AccessRefusal extends Throwable
{
    /**
     * Which of the closed set of reasons this refusal carries (D-26).
     */
    public function reason(): RefusalReason;
}

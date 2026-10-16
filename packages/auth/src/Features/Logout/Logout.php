<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\Logout;

/**
 * One request to end a session.
 *
 * **It carries the refresh token and nothing that could widen it.** There is
 * no user id, no family id and no "all sessions" flag: a caller can end the
 * chain it holds a token for, and nothing else. A field naming a family
 * would let anybody who guessed one sign somebody else out, and the only
 * thing that proves which chain is yours is the token itself.
 *
 * `token` is `mixed` for the same reason the refresh command's is: an
 * unknown, malformed or absent value is answered, not refused (AC-49), so
 * nothing here may be a `TypeError`.
 */
final class Logout
{
    public function __construct(
        public readonly mixed $token,
    ) {}
}

<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\Login;

use Flexipos\Auth\Support\IpAddress;

/**
 * One attempt to sign in with an email and a password.
 *
 * **The command is the whole of AC-10.** "Extra fields such as `tenant_id`,
 * `database`, `host` have no effect" is not a thing this slice checks and
 * discards - there is nowhere for such a field to arrive. The command has
 * five properties, two of them credentials and three of them facts about the
 * caller taken from the connection rather than from the body, and a
 * controller that wanted to honour a submitted `database` would have to add a
 * property here to do it. That is why AC-10's proof is structural (the
 * constructor's parameter list) as well as behavioural (the same token set
 * for a body with and without the extras): ARCH.md section 5 says a client
 * tenant identifier is a request and not a fact, and the way to keep it from
 * becoming a fact is to give it no column to sit in.
 *
 * **`email` and `password` are `mixed` on purpose.** They are whatever the
 * request decoded to, and AC-9 includes "a non-string value for either" as a
 * case that must produce a 422 rather than a `TypeError`.
 * {@see LoginCredentials::from()} is where they become values, and it is the
 * first statement of the handler.
 *
 * **`ip` is the printed address**, not the packed form - the conversion
 * happens once, in {@see IpAddress}, at each of the
 * columns that stores it (AC-27).
 */
final class Login
{
    public function __construct(
        public readonly mixed $email,
        public readonly mixed $password,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $deviceId = null,
    ) {}
}

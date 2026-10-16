<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\SelectTenant;

use Flexipos\Auth\Support\IpAddress;

/**
 * One attempt to open a shop, by the person a platform token names.
 *
 * **The command is the whole of AC-43**, in the same way {@see
 * \Flexipos\Auth\Features\Login\Login} is the whole of AC-10. "A body that
 * also carries `tenant_id`, `database`, `host`, `driver` - those are
 * ignored" is not something this slice checks and discards: **there is
 * nowhere for such a field to arrive.** The command has five properties -
 * who is asking (from the verified token, never from the body), which code
 * they asked for, and three facts about the caller taken from the connection
 * - and a controller that wanted to honour a submitted `database` would have
 * to add a property here to do it. `Refference/ARCH.md` section 5: a client
 * tenant identifier is a request, not a fact, and the way to keep it from
 * becoming a fact is to give it no column to sit in.
 *
 * **`userId` comes from the token's `sub` and from nothing else.** It is the
 * one input the caller cannot choose: step 12's middleware verifies the
 * platform token and hands the subject here. A `user_id` in a body would be
 * impersonation with extra steps.
 *
 * **`code` is `mixed` on purpose.** AC-41 includes "a non-string value" as a
 * case that must produce a 422 rather than a `TypeError`.
 * {@see TenantCodeInput::from()} is where it becomes a value, and it is the
 * first statement of the handler.
 *
 * **`ip` is the printed address**, not `inet_pton`'s packed form; the
 * conversion happens once, in {@see IpAddress}, at each column that stores it
 * (AC-27).
 */
final class SelectTenant
{
    public function __construct(
        public readonly string $userId,
        public readonly mixed $code,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $deviceId = null,
    ) {}
}

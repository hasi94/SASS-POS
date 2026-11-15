<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\RefreshToken;

use Flexipos\Auth\Support\IpAddress;

/**
 * One attempt to exchange a refresh token for a new pair.
 *
 * **The command is the whole of AC-38's first half.** "A refresh request
 * that also carries `tenant`, `tenant_id`, `database` or `scope` - the field
 * is ignored" is not a thing this slice checks and discards: there is
 * nowhere for such a field to arrive. The issued token's tenant is the
 * stored row's, or none, because the stored row is the only place the
 * handler can read one from. A caller that wanted to choose would need a
 * sixth property here, and it would be in the diff.
 *
 * **`token` is `mixed`** for the reason the login command's two credentials
 * are: it is whatever the request body decoded to, and AC-35 lists "empty,
 * or malformed" as cases that must produce the generic refusal rather than a
 * `TypeError`. **Unlike a login there is no 422 here at all** - a refresh
 * token is not a form field a person fills in, it is a value this system
 * issued, so "unreadable" and "not one of ours" are the same answer and
 * telling them apart would say which strings are shaped like real tokens.
 *
 * **`ip` is the printed address**; the packing happens once, in
 * {@see IpAddress}, at the column that stores it.
 */
final class RefreshToken
{
    public function __construct(
        public readonly mixed $token,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $deviceId = null,
    ) {}
}

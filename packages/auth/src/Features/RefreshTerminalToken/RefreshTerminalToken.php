<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\RefreshTerminalToken;

/**
 * A terminal presenting its refresh token (T-014 AC-36). `token` is `mixed`
 * and unjudged: a non-string is "not a token of ours", the same refusal as an
 * unknown one.
 */
final class RefreshTerminalToken
{
    public function __construct(
        public readonly mixed $token,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}
}

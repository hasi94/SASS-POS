<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\VerifyAccessToken;

use Flexipos\Auth\Tokens\TokenClaims;
use InvalidArgumentException;

/**
 * Verify one access token for one operation.
 *
 * **The required token type is part of the question, not an afterthought.**
 * AC-29's last refusal is "a platform token presented where a tenant token is
 * required, and the reverse", so there is no such thing as verifying a token
 * in the abstract: the caller always knows which kind of token its route
 * takes, and a verifier that answered "valid" without being told would leave
 * that check to every caller to remember.
 *
 * The token string is accepted exactly as presented, including rubbish: the
 * handler decides what is malformed, so that an empty or truncated value is
 * one refusal with a reason rather than a type error somewhere upstream.
 */
final class VerifyAccessToken
{
    public function __construct(
        public readonly string $token,
        public readonly string $requiredTokenType,
    ) {
        if (! in_array($requiredTokenType, TokenClaims::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('unknown token type %s', $requiredTokenType));
        }
    }
}

<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\IssueTokenPair;

/**
 * What one issue produced.
 *
 * `refreshToken` is the **plaintext**, and it exists here because the caller
 * has to render it once and never again: the row holds only its SHA-256 hex
 * (AC-32). Nothing persists this object, and nothing logs it.
 *
 * `claims` is returned as well as encoded so a caller - and the slice's own
 * test - can compare the claim-name set for equality without decoding the
 * token it just minted, which would prove the decoder rather than the minter.
 */
final class IssueTokenPairResult
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $kid,
        public readonly array $claims,
        public readonly string $refreshToken,
        public readonly string $refreshTokenId,
        public readonly string $familyId,
        public readonly string $expiresAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\RefreshToken;

/**
 * What one rotation produced: a new access token, a new refresh token, and
 * the family both of them continue.
 *
 * `familyId` is returned because a rotation is defined by it - AC-33 asks
 * for "a new refresh token in the **same** `family_id`", and a caller or a
 * test that had to read the table to learn which family it got would be
 * asserting against the thing under test.
 *
 * `tenantId` is the stored row's and never a caller's (AC-38). It is null
 * for a platform rotation, which is how a reader can tell the two shapes
 * apart without decoding the access token.
 */
final class RefreshTokenResult
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly string $familyId,
        public readonly ?string $tenantId,
    ) {}

    /**
     * The response body, and the only one.
     *
     * @return array{access_token: string, refresh_token: string}
     */
    public function toResponse(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
        ];
    }
}

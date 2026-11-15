<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\SelectTenant;

use Flexipos\Auth\Features\Login\LoginResult;

/**
 * What one successful tenant selection produced, and the exact body the
 * caller renders.
 *
 * `toResponse()` is here rather than in the controller for the reason
 * {@see LoginResult} gives: the shape is an
 * assertion the slice's own test can make, and a second spelling in a
 * controller is where a `database` key gets added by somebody debugging.
 *
 * **What is deliberately absent.** No database name, host, port or
 * credential reference - a tenant token says *which* shop, never *where* its
 * data lives, because the frontend never names a database. No tenant list
 * either: selecting is not logging in, and the list was already answered.
 *
 * `tenantId` and `tenantCode` are both here because they answer different
 * questions: the id is what the token carries and what a later request is
 * scoped by, and the code is what the caller asked for, echoed so a client
 * switching shops can match the response to the request it made.
 */
final class SelectTenantResult
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $tenantCode,
        public readonly string $accessToken,
        public readonly string $refreshToken,
        /**
         * The rotation chain this selection started (AC-30: selecting A makes
         * F1 and selecting B makes F2, and the platform family F0 is neither
         * used nor revoked). Returned so a test - and, later, a session
         * management screen - can name a family without reading the table.
         */
        public readonly string $familyId,
    ) {}

    /**
     * The response body, and the only one.
     *
     * @return array{access_token: string, refresh_token: string, tenant_id: string, tenant_code: string}
     */
    public function toResponse(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'tenant_id' => $this->tenantId,
            'tenant_code' => $this->tenantCode,
        ];
    }
}

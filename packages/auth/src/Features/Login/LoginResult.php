<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\Login;

/**
 * What one successful login produced, and the exact body the caller renders.
 *
 * **`toResponse()` is here rather than in the controller because AC-1 is an
 * assertion about a value.** The criterion says the response carries four
 * things and carries no `password_hash`, no database name, host, port or
 * credential reference. A controller that assembled the body itself would put
 * that assertion out of reach of the slice's own test and into an HTTP test
 * that has to spell the shape a second time - and the second spelling is
 * where a `database` key gets added by somebody who is debugging. One
 * producer, one shape, one place to scan.
 *
 * **What is deliberately absent.** No user id (the access token's `sub`
 * already carries it and a response does not need to), no email, no tenant
 * *database* of any kind: the login response names tenants by `id`, `code`,
 * `name` and `status` and says nothing about where their data lives, because
 * the frontend never names a database (architecture.md) and cannot be invited
 * to start by being handed one.
 *
 * **No tenant token is here either**, for any tenant, selectable or not
 * (AC-5, AC-14). Logging in is not selecting; `SelectTenant` is a separate
 * operation with its own refusals.
 */
final class LoginResult
{
    /**
     * @param  list<array{id: string, code: string, name: string, status: string, selectable: bool, is_default: bool}>  $tenants
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly array $tenants,
        public readonly ?string $defaultTenantId,
        public readonly bool $mustChangePassword,
    ) {}

    /**
     * The response body, and the only one.
     *
     * @return array{access_token: string, refresh_token: string, tenants: list<array{id: string, code: string, name: string, status: string, selectable: bool, is_default: bool}>, default_tenant_id: string|null, must_change_password: bool}
     */
    public function toResponse(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'tenants' => $this->tenants,
            'default_tenant_id' => $this->defaultTenantId,
            'must_change_password' => $this->mustChangePassword,
        ];
    }
}

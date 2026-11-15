<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\RequirePlatformToken;
use App\Http\Middleware\RequireTenantToken;
use App\Http\Middleware\RequireTerminalToken;
use Illuminate\Http\Request;

/**
 * Who a request is, read from the attributes the token middleware already
 * set (T-021 Ruling 5), used by both the rate limiter and the idempotency
 * middleware so the two never disagree about identity.
 *
 * | Token | Buckets | Limit key |
 * |---|---|---|
 * | tenant | `t:{tenant}`, `u:{tenant}:{sub}` | tenant, user |
 * | terminal | `t:{tenant}`, `d:{tenant}:{terminal}` | tenant, terminal |
 * | platform | `u:platform:{sub}` | user |
 * | none | `ip:{ip}` | unauthenticated |
 *
 * A terminal token has no user claim, so it never gets a user bucket; a
 * platform token has no tenant, so it never gets a tenant bucket (AC-53).
 */
final readonly class ApiActor
{
    private function __construct(
        public ?string $tenantBucket,
        public ?string $userBucket,
        public ?string $terminalBucket,
        public ?string $ipBucket,
        /** `user:{sub}` or `terminal:{id}`, for the idempotency fingerprint. */
        public string $identity,
    ) {}

    public static function from(Request $request): self
    {
        $tenantId = $request->attributes->get(RequireTenantToken::TENANT_ID);
        $terminalTenantId = $request->attributes->get(RequireTerminalToken::TENANT_ID);
        $terminalId = $request->attributes->get(RequireTerminalToken::TERMINAL_ID);

        if ($tenantId !== null) {
            $userId = (string) $request->attributes->get(RequireTenantToken::USER_ID);

            return new self(
                tenantBucket: "t:{$tenantId}",
                userBucket: "u:{$tenantId}:{$userId}",
                terminalBucket: null,
                ipBucket: null,
                identity: "user:{$userId}",
            );
        }

        if ($terminalTenantId !== null) {
            return new self(
                tenantBucket: "t:{$terminalTenantId}",
                userBucket: null,
                terminalBucket: "d:{$terminalTenantId}:{$terminalId}",
                ipBucket: null,
                identity: "terminal:{$terminalId}",
            );
        }

        $platformUserId = $request->attributes->get(RequirePlatformToken::USER_ID);

        if ($platformUserId !== null) {
            return new self(
                tenantBucket: null,
                userBucket: "u:platform:{$platformUserId}",
                terminalBucket: null,
                ipBucket: null,
                identity: "user:{$platformUserId}",
            );
        }

        $ip = (string) $request->ip();

        return new self(
            tenantBucket: null,
            userBucket: null,
            terminalBucket: null,
            ipBucket: "ip:{$ip}",
            identity: "ip:{$ip}",
        );
    }

    public function isAuthenticated(): bool
    {
        return $this->ipBucket === null;
    }
}

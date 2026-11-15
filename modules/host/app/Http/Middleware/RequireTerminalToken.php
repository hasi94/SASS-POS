<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AuthRefusals;
use Closure;
use Flexipos\Auth\Exceptions\AccessTokenRefused;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessToken;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessTokenHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Organization\TerminalAuthority;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A terminal access token, verified, its tenant entered, and the terminal
 * checked against the tenant database **on every request** (T-014 AC-34,
 * AC-35, D-19).
 *
 * **The tenant database is the authority, not the token.** A terminal token
 * lives 15 minutes and "revoked" has to mean the next request, so after the
 * token verifies and the tenant is entered, the terminal row and its
 * credential are read: the terminal must be `active`, its credential
 * `active`, and its branch must equal the token's single branch. Anything
 * else - paused, revoked, missing, a credential revoked while the row still
 * says active, a branch that moved - is the same refusal, **a 401 that gives
 * no reason**, because a caller told which of them it was learns something
 * about a shop's state it has not proved it may know. No write per request.
 *
 * **Route separation is by token type, in the verifier**: a tenant employee
 * token or a platform token presented here is `wrong_token_type` (403), and a
 * terminal token presented to `RequireTenantToken` or `RequirePlatformToken`
 * is refused the same way (AC-35). A terminal never satisfies an employee
 * permission route: this class is the only terminal-verifying middleware and
 * no permission middleware follows it.
 *
 * `leave()` is in a `finally`, as in `RequireTenantToken`, so a handler that
 * throws cannot leave a tenant entered for the next request in a long-running
 * worker.
 */
final class RequireTerminalToken
{
    /** The request attribute carrying the verified claim set. */
    public const CLAIMS = 'auth.terminal_claims';

    /** The request attribute carrying the terminal's id. */
    public const TERMINAL_ID = 'auth.terminal_id';

    /** The request attribute carrying the terminal's branch id. */
    public const BRANCH_ID = 'auth.terminal_branch_id';

    /** The request attribute carrying the tenant id. */
    public const TENANT_ID = 'auth.terminal_tenant_id';

    public function __construct(
        private readonly VerifyAccessTokenHandler $verifier,
        private readonly Tenancy $tenancy,
        private readonly TerminalAuthority $authority,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return AuthRefusals::credentials();
        }

        try {
            $claims = $this->verifier->handle(new VerifyAccessToken($token, TokenClaims::TYPE_TERMINAL));
        } catch (AccessTokenRefused $refused) {
            return $refused->reason() === AccessTokenRefused::REASON_WRONG_TOKEN_TYPE
                ? AuthRefusals::wrongTokenType()
                : AuthRefusals::credentials();
        }

        $tenantId = (string) $claims['tenant'];
        $terminalId = (string) $claims['terminal_id'];
        $branchIds = $claims['branch_ids'];
        $claimedBranch = is_array($branchIds) && count($branchIds) === 1 && is_string($branchIds[0]) ? $branchIds[0] : null;

        $this->tenancy->enter($tenantId);

        try {
            $actual = $this->authority->activeBranchOf($terminalId);

            if ($actual === null || $claimedBranch === null || $actual !== $claimedBranch) {
                return AuthRefusals::credentials();
            }

            $request->attributes->set(self::CLAIMS, $claims);
            $request->attributes->set(self::TERMINAL_ID, $terminalId);
            $request->attributes->set(self::BRANCH_ID, $actual);
            $request->attributes->set(self::TENANT_ID, $tenantId);

            return $next($request);
        } finally {
            $this->tenancy->leave();
        }
    }
}

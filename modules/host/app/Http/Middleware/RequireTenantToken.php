<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AuthRefusals;
use Closure;
use Flexipos\Auth\Exceptions\AccessTokenRefused;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessToken;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessTokenHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tenant access token, verified, and the tenant it names entered - in one
 * class (AC-45, AC-46, AC-47).
 *
 * **One class is the design, not a convenience.** D-9 lets tenant selection
 * skip the mapping probe entirely: it never asks whether the shop's database
 * is servable, because a tenant token is useless on its own. That is only
 * true while reading the token and entering the tenant are the same act, so
 * they are the same class with no path between them. A route cannot be
 * authenticated here without also being gated, because there is no
 * middleware that does one and not the other.
 *
 * **The tenant comes from the token and from nothing else (AC-45).** The
 * `tenant` claim of a signed, verified token is the only input to
 * {@see Tenancy::enter()}. A body, a query string, a header or a path
 * parameter naming another tenant - or a database - is never read for
 * tenancy; there is no code here that could read one. That is why the
 * criterion is about absence: a request carrying `tenant=B` resolves A
 * because nothing looks at `tenant=B` at all.
 *
 * **The serving gate is the kernel's and stays there (AC-47).** This class
 * holds no copy of the tenant-database status list and no reference to the
 * types that hold it; `enter()` refuses a mapping that is not `active` with
 * `TenantDatabaseNotActive`, an exception authentication never constructs.
 * It is deliberately not caught here: it is a tenant that cannot be served,
 * not a caller that cannot be identified, and swallowing it into a 401 or a
 * 403 would make a deployment problem look like a credential one.
 *
 * **`leave()` in a `finally`.** `enter()` writes the `tenant` connection
 * stanza for this request; leaving it behind would hand the next request in
 * a long-running worker a tenant nobody asked for, which is the single worst
 * failure this platform can have. The pair is a `finally` so a handler that
 * throws cannot skip it.
 */
final class RequireTenantToken
{
    /**
     * The request attribute carrying the verified subject.
     */
    public const USER_ID = 'auth.user_id';

    /**
     * The request attribute carrying the verified tenant.
     */
    public const TENANT_ID = 'auth.tenant_id';

    /**
     * The request attribute carrying the whole verified claim set.
     */
    public const CLAIMS = 'auth.claims';

    public function __construct(
        private readonly VerifyAccessTokenHandler $verifier,
        private readonly Tenancy $tenancy,
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
            $claims = $this->verifier->handle(new VerifyAccessToken($token, TokenClaims::TYPE_TENANT));
        } catch (AccessTokenRefused $refused) {
            // AC-46: a platform token here is 403 - the caller holds a token
            // and presented the wrong kind - and an invalid or expired one is
            // the 401 generic refusal. Neither reaches a handler.
            return $refused->reason() === AccessTokenRefused::REASON_WRONG_TOKEN_TYPE
                ? AuthRefusals::wrongTokenType()
                : AuthRefusals::credentials();
        }

        // Guaranteed by the verifier: a tenant token whose claim names are
        // not exactly the tenant set is refused above as `claim_set`, and
        // `tenant` is in that set.
        $tenantId = (string) $claims['tenant'];

        $request->attributes->set(self::CLAIMS, $claims);
        $request->attributes->set(self::USER_ID, (string) $claims['sub']);
        $request->attributes->set(self::TENANT_ID, $tenantId);

        $this->tenancy->enter($tenantId);

        try {
            return $next($request);
        } finally {
            $this->tenancy->leave();
        }
    }
}

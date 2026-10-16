<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AuthRefusals;
use Closure;
use Flexipos\Auth\Exceptions\AccessTokenRefused;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessToken;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessTokenHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A platform access token, verified, or the request does not reach a handler
 * (AC-42).
 *
 * Tenant selection is the one operation this guards today: the caller has
 * logged in and holds a platform token, and is asking which shop to open.
 * There is no tenant yet, so nothing here enters one - that is
 * {@see RequireTenantToken}, and the two are deliberately separate classes
 * because they guard different things.
 *
 * **The subject is the token's, never the request's.** The verified `sub`
 * claim is put on the request as {@see self::USER_ID} and that is the only
 * thing downstream reads: a body or query string naming a user is not
 * consulted, which is the same rule AC-45 states for tenants.
 *
 * **Two statuses, one branch.** A token of the wrong *type* is 403 and
 * everything else is the 401 generic refusal; the branch is on
 * {@see AccessTokenRefused::REASON_WRONG_TOKEN_TYPE}, which is the verifier's
 * own constant rather than a word repeated here. The verifier checks the type
 * before the claim set on purpose (step 5's measured mask), so a tenant token
 * presented here is refused as a type and not as a shape.
 */
final class RequirePlatformToken
{
    /**
     * The request attribute carrying the verified subject.
     */
    public const USER_ID = 'auth.user_id';

    /**
     * The request attribute carrying the whole verified claim set, for a
     * handler that needs more than the subject.
     */
    public const CLAIMS = 'auth.claims';

    public function __construct(private readonly VerifyAccessTokenHandler $verifier) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            // AC-42: no token is 401, the same answer as an invalid one. A
            // caller learns that it is not authenticated and nothing else.
            return AuthRefusals::credentials();
        }

        try {
            $claims = $this->verifier->handle(new VerifyAccessToken($token, TokenClaims::TYPE_PLATFORM));
        } catch (AccessTokenRefused $refused) {
            return $refused->reason() === AccessTokenRefused::REASON_WRONG_TOKEN_TYPE
                ? AuthRefusals::wrongTokenType()
                : AuthRefusals::credentials();
        }

        $request->attributes->set(self::CLAIMS, $claims);
        $request->attributes->set(self::USER_ID, (string) $claims['sub']);

        return $next($request);
    }
}

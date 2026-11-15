<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AccessRefusals;
use App\Http\Responses\AuthRefusals;
use Closure;
use Flexipos\Auth\Exceptions\AccessTokenRefused;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessToken;
use Flexipos\Auth\Features\VerifyAccessToken\VerifyAccessTokenHandler;
use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Access\EmployeeStalenessCheck;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tenant access token, verified, the tenant it names entered, and the
 * employee claims checked for staleness - in one class (T-009 AC-45 to
 * AC-47, T-010 AC-66 to AC-68).
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
        private readonly EmployeeStalenessCheck $staleness,
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
            if ($this->isStale($claims)) {
                return AccessRefusals::permissionsChanged();
            }

            return $next($request);
        } finally {
            $this->tenancy->leave();
        }
    }

    /**
     * D-7's staleness check, inside this class and after `enter()`.
     *
     * **Inside, not beside** (plan section 1). A route must not be able to
     * be authenticated without also being gated, and the tenant connection
     * this question needs is opened by this class and by nothing else. A
     * second middleware would be a second thing a route file could forget.
     *
     * **Both claims or no check, and that is a composition rather than a
     * hole.** The five employee-derived claims are minted together or not
     * at all (AC-58), and the verifier above refuses a tenant token whose
     * claim names are not *exactly* one of the two legal sets - with the
     * five, or without any of them. So a token carrying `employee` and no
     * `perm_version` never reaches this method: it is refused as
     * `claim_set` one step earlier. The guard below is therefore reading
     * the two legal shapes apart, not defaulting an absent input to
     * "permit".
     *
     * A token with **no** `employee` claim performs no check at all
     * (AC-66): there is no employee whose rights could have changed, and
     * every guarded route refuses such a token `not_permitted` anyway.
     *
     * **An `employee` claim beside a malformed one is stale, not fresh**
     * (review S-4). A signed token cannot carry that shape today, because
     * the verifier refuses it first; but if that ever stopped being true,
     * an empty employee id or a `perm_version` that is not an integer must
     * fail closed, as every other unreadable input in this class does,
     * rather than skip the check and let the token through.
     *
     * @param  array<string, mixed>  $claims
     */
    private function isStale(array $claims): bool
    {
        if (! array_key_exists('employee', $claims) || $claims['employee'] === null) {
            return false;
        }

        $employeeId = $claims['employee'];
        $claimed = $claims['perm_version'] ?? null;

        if (! is_string($employeeId) || $employeeId === '' || ! is_int($claimed)) {
            return true;
        }

        return $this->staleness->isStale($employeeId, $claimed);
    }
}

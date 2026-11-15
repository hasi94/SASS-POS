<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AccessRefusals;
use Closure;
use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Contracts\Access\PermissionResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The permission guard: one declaration per route, resolved against the
 * tenant database (AC-63, AC-64, AC-69).
 *
 * **It runs behind {@see RequireTenantToken} and never beside it.** The
 * tenant is entered by that class and by nothing else, so by the time this
 * one runs there is a tenant connection and a verified claim set; the
 * employee it judges comes from the token's `employee` claim and from no
 * submitted field. A route that carried this middleware without that one
 * would have no tenant to read, which is why the route file declares them
 * as a pair.
 *
 * **`authenticated_only` is a value this class recognises, not a second
 * class** (plan section 1). AC-63 requires every tenant route to declare
 * exactly one of a permission key or that marker; making the marker a
 * different middleware would mean a route could carry neither and look
 * deliberate. Here the declaration is always present and always one string,
 * and the route sweep reads it from the router.
 *
 * **The decision is the resolver's, and the reason the caller sees is
 * always `not_permitted`** (D-26). `not_granted`, `explicit_deny` and
 * `out_of_scope` are written to `access_log.detail.reason` and never to an
 * HTTP body: telling a caller that an explicit deny exists discloses
 * something about how the shop is administered and buys them nothing.
 *
 * **A token with no `employee` claim is refused and writes no log row.**
 * Platform staff with no employee (AC-72, T-009 D-18) hold such a token;
 * every guarded route refuses them, which is AC-66's clause. No
 * `access_log` row is written for it, deliberately: `employee_id` would be
 * null, and `detail.reason` is closed at three causes that all describe the
 * state of somebody's grants. A row attributable to nobody, giving a reason
 * about grants that were never read, would be a worse record than none -
 * and AC-69 already excludes authentication-shaped refusals from the log.
 *
 * **The question is asked at a null scope**, because no route carries a
 * branch yet: branches, terminals and their transport are FP-209's. A
 * branch-scoped key declared on a route therefore answers `branch_required`
 * and the request is refused - fail closed, and visible the first time such
 * a route exists rather than silently allowed.
 */
final class RequireTenantPermission
{
    /**
     * The declaration a route carries when it needs a valid tenant token and
     * no permission key (AC-63).
     */
    public const AUTHENTICATED_ONLY = 'authenticated_only';

    public function __construct(
        private readonly PermissionResolver $resolver,
        private readonly TenantConnection $tenant,
        private readonly AccessLogWriter $log,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $declaration): Response
    {
        if ($declaration === self::AUTHENTICATED_ONLY) {
            return $next($request);
        }

        $employeeId = self::employeeId($request);

        if ($employeeId === null) {
            return AccessRefusals::notPermitted();
        }

        $decision = $this->resolver->decide($employeeId, $declaration, null);

        if ($decision->allowed) {
            return $next($request);
        }

        // Exactly one row, written before the response is built, so a
        // refusal cannot be answered without being recorded. The finer
        // cause is the decision's detail when it has one - an unregistered
        // key has no grant state to describe, so the reason itself is
        // recorded instead, which is the row an operator needs to find the
        // typo the sweep did not catch.
        $this->log->permissionDenied(
            $this->tenant->get(),
            $employeeId,
            $declaration,
            null,
            $decision->detail ?? $decision->reason->value,
            $request->ip(),
        );

        return AccessRefusals::notPermitted();
    }

    /**
     * The acting employee, from the verified token's claims.
     *
     * Null when the token carries no `employee` claim: a membership whose
     * `employee_id` is null mints one of those (T-009 D-18), and it is a
     * valid token for an `authenticated_only` route and for nothing else.
     */
    public static function employeeId(Request $request): ?string
    {
        $claims = $request->attributes->get(RequireTenantToken::CLAIMS);

        if (! is_array($claims)) {
            return null;
        }

        $employeeId = $claims['employee'] ?? null;

        return is_string($employeeId) && $employeeId !== '' ? $employeeId : null;
    }
}

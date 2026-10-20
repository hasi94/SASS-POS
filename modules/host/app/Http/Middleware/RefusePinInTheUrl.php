<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use Closure;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A PIN arrives in the body or it does not arrive (AC-48, sink 6).
 *
 * **The URL is an ingress channel, not a sink, which is why no scan of
 * written places can find this defect.** Nothing in the application writes
 * the request URL anywhere, so a capture harness that examines every column,
 * the log, the responses and the token claims would report a clean run while
 * every PIN sat in nginx's `access.log`, in browser history, in proxy logs
 * and in the `Referer` of the next request. T-009 shipped passwords readable
 * from the query string with 1145 tests green, for exactly this reason: the
 * only places that could have caught it were the reader and the route.
 *
 * **Refusing the channel is the half the application can keep.** It cannot
 * erase what a web server logged before the request reached PHP, and the
 * report must say so. What it can do is never accept the value from there -
 * {@see AccessInput} reads the body only, so a PIN in the
 * query string is already ignored - and refuse the request outright, so that
 * a client sending one is told rather than silently served.
 *
 * **It runs before the permission guard, and that ordering is the
 * criterion.** AC-48 asks for the refusal "before any state is read or
 * written": placed after {@see RequireTenantPermission} this class would
 * answer only after the resolver had read the grant tables and, for an
 * unprivileged caller, after an `access_log` row had been written. Placed
 * here it answers before either. It cannot run before
 * {@see RequireTenantToken}, which is what enters the tenant - but that
 * class reads no submitted field at all.
 *
 * **It is one class and not a line in the controller** because the rule is
 * about the transport, not about setting a PIN: any later route that
 * carries a credential adds this middleware rather than re-deciding the
 * question.
 */
final class RefusePinInTheUrl
{
    /**
     * The field names that may never appear in a URL.
     *
     * `pin_hash` is here beside `pin` because a client sending the digest
     * is sending a credential equivalent for an offline attacker (AC-52a),
     * and because a column name in a URL is a sign of a client composing
     * requests out of the schema.
     *
     * @var list<string>
     */
    public const FORBIDDEN = ['pin', 'pin_hash'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::FORBIDDEN as $name) {
            if ($request->query->has($name)) {
                return self::refuse();
            }
        }

        // The path too, not only the parsed query bag: a route of the shape
        // `/employees/{id}/pin/1234` would put the value in the path, where
        // `query()` never looks and where every log keeps it just the same.
        // Matched on the decoded path so that an encoded separator cannot
        // hide it.
        if (preg_match('#(\A|/)(pin|pin_hash)[=:/][^/]#i', rawurldecode($request->path())) === 1) {
            return self::refuse();
        }

        return $next($request);
    }

    /**
     * The refusal, carrying no echo of what was submitted.
     *
     * The body is the plain `validation` reason and names neither the
     * parameter nor its value: a refusal that quoted the offending query
     * string would re-emit the credential into the response - and into
     * whatever logs the response, which is the sink this class exists to
     * keep empty.
     */
    private static function refuse(): Response
    {
        return AccessRefusals::of(RefusalReason::Validation);
    }
}

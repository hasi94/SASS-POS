<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Flexipos\Kernel\Http\RequestId;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mint one correlation id per HTTP request (T-017 Ruling 1, AC-1, AC-12).
 *
 * **Prepended** in `bootstrap/app.php`, so it runs before anything that might
 * write an audit row. It stores a fresh ULID under
 * {@see RequestId::ATTRIBUTE} as a request attribute, and echoes the same
 * value back as the `X-Request-Id` response header - the one externally
 * visible change this task makes (plan section 4, "HTTP").
 *
 * **An inbound `X-Request-Id` is ignored, never adopted.** A client-chosen
 * value would let a caller plant one request's identifier on another
 * request's audit rows; this middleware always mints its own and always
 * overwrites whatever header the response otherwise carried.
 */
final class AssignRequestId
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = Str::ulid()->toBase32();

        $request->attributes->set(RequestId::ATTRIBUTE, $id);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}

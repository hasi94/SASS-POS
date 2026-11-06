<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Requests\ApiActor;
use Closure;
use Flexipos\Kernel\Api\ApiResponse;
use Flexipos\Kernel\Api\Idempotency\IdempotencyClaim;
use Flexipos\Kernel\Api\Idempotency\IdempotencyFingerprint;
use Flexipos\Kernel\Api\Idempotency\IdempotencyKey;
use Flexipos\Kernel\Api\Idempotency\IdempotencyStore;
use Flexipos\Kernel\Api\KernelErrorCodes;
use Flexipos\Kernel\Http\RequestId;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opt-in per route (T-021 Ruling 6, AC-16 to AC-22, AC-24, AC-25, AC-27).
 *
 * Declared with `->middleware(self::class)` on the route; no alias, because
 * every other route-level middleware here is named by class too.
 *
 * **Tenant-only (D-7).** A route with this middleware and no resolved
 * tenant is a configuration error, refused as 500 `kernel.server_error`
 * rather than storing a key in the wrong database.
 *
 * **Ordered after `ThrottleApi` in the priority list** (`bootstrap/app.php`),
 * so a replay still counts against the rate limits (AC-27).
 */
final class RequireIdempotencyKey
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly IdempotencyStore $store,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenancy->current()->hasTenant()) {
            Log::error('RequireIdempotencyKey used on a route with no resolved tenant.');

            return ApiResponse::failure(KernelErrorCodes::SERVER_ERROR);
        }

        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return ApiResponse::failure(KernelErrorCodes::IDEMPOTENCY_KEY_REQUIRED);
        }

        if (! IdempotencyKey::isValid($key)) {
            return ApiResponse::failure(KernelErrorCodes::IDEMPOTENCY_KEY_INVALID);
        }

        $actor = ApiActor::from($request);
        $hash = IdempotencyFingerprint::of($request->method(), $request->path(), $actor->identity, (string) $request->getContent());

        $claim = $this->store->claim($key, $hash);

        return match ($claim->outcome) {
            IdempotencyClaim::Reused => ApiResponse::failure(KernelErrorCodes::IDEMPOTENCY_KEY_REUSED),
            IdempotencyClaim::InProgress => ApiResponse::failure(KernelErrorCodes::IDEMPOTENCY_IN_PROGRESS, headers: [
                'Retry-After' => (string) config('api.idempotency.in_progress_retry_after', 1),
            ]),
            IdempotencyClaim::Replay => $this->replay($claim->responseCode ?? 500, $claim->responseBody),
            IdempotencyClaim::Claimed => $this->runAndStore($request, $next, $key, $hash),
        };
    }

    private function replay(int $status, mixed $body): Response
    {
        // Decoded without `true` on the storage side (S-9), so an envelope's
        // `meta` is a `stdClass`, not an array; only patch whichever shape
        // actually shows up, so an empty JSON object elsewhere in the body
        // is never forced through an assoc-array round-trip that would turn
        // it into `[]`.
        if (is_object($body) && isset($body->meta) && is_object($body->meta)) {
            $body->meta->request_id = app(RequestId::class)->current();
        } elseif (is_array($body) && array_key_exists('meta', $body) && is_array($body['meta'])) {
            $body['meta']['request_id'] = app(RequestId::class)->current();
        }

        return response()->json($body, $status)->withHeaders(['Idempotent-Replayed' => 'true']);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function runAndStore(Request $request, Closure $next, string $key, string $hash): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->store->release($key, $hash);

            throw $exception;
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            $this->store->release($key, $hash);

            return $response;
        }

        // Decoded without `true` (S-9): an assoc-array round-trip cannot
        // tell an empty JSON object from an empty JSON array, so it would
        // silently turn a stored `{}` into `[]` the next time this gets
        // replayed.
        $decoded = json_decode((string) $response->getContent());

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Idempotent handler answered a 2xx with a non-JSON body; nothing was stored.');
            $this->store->release($key, $hash);

            return $response;
        }

        $this->store->complete($key, $hash, $status, $decoded);

        return $response;
    }
}

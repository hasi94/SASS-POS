<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Requests\ApiActor;
use App\Support\Api\ApiLimits;
use Closure;
use Flexipos\Kernel\Api\ApiResponse;
use Flexipos\Kernel\Api\KernelErrorCodes;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Three independent buckets - tenant, user, terminal - for an authenticated
 * `api/*` request, or one IP bucket for an unauthenticated one (T-021
 * Ruling 5, AC-27 to AC-33b).
 *
 * **Appended to the `api` middleware group, ordered after the token
 * middleware by Laravel's priority list** (`bootstrap/app.php`): identity
 * exists only after `RequireTenantToken`/`RequireTerminalToken`/
 * `RequirePlatformToken` has run, and those are route-level, not group-level.
 *
 * **Fails open on a limiter-store outage (D-10).** Every limiter call sits
 * inside one try/catch; on failure the request is allowed with no rate
 * headers, and exactly one warning is logged.
 */
final class ThrottleApi
{
    /**
     * Laravel's own fixed 60-second window.
     */
    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly ApiLimits $limits,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = ApiActor::from($request);

        try {
            $decision = $this->decide($actor);
        } catch (Throwable $exception) {
            Log::warning('Rate limiter unavailable; request allowed.', [
                'exception' => $exception::class,
            ]);

            return $next($request);
        }

        if ($decision['exhausted']) {
            return ApiResponse::failure(KernelErrorCodes::RATE_LIMITED, headers: $decision['headers']);
        }

        $response = $next($request);

        $response->headers->add($decision['headers']);

        return $response;
    }

    /**
     * Every limiter call this middleware makes, in one place, so the whole
     * decision - exhausted or not, and every header either answer carries -
     * sits behind a single try/catch (D-10): a store outage must fail open
     * before `$next($request)` ever runs, never partway through it.
     *
     * @return array{exhausted: bool, headers: array<string, string>}
     */
    private function decide(ApiActor $actor): array
    {
        $buckets = $this->bucketsFor($actor);

        $exhausted = null;

        foreach ($buckets as $bucket => $limit) {
            if ($this->limiter->tooManyAttempts($bucket, $limit)) {
                $exhausted ??= $bucket;
            }
        }

        if ($exhausted !== null) {
            $retryAfter = 1;

            foreach ($buckets as $bucket => $limit) {
                if ($this->limiter->tooManyAttempts($bucket, $limit)) {
                    $retryAfter = max($retryAfter, $this->limiter->availableIn($bucket));
                }
            }

            return [
                'exhausted' => true,
                'headers' => [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $buckets[$exhausted],
                    'X-RateLimit-Remaining' => '0',
                ],
            ];
        }

        foreach ($buckets as $bucket => $limit) {
            $this->limiter->hit($bucket, self::DECAY_SECONDS);
        }

        [$limit, $remaining] = $this->tightest($buckets);

        return [
            'exhausted' => false,
            'headers' => [
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => (string) $remaining,
            ],
        ];
    }

    /**
     * @return array<string, int> bucket key => its configured limit
     */
    private function bucketsFor(ApiActor $actor): array
    {
        $buckets = [];

        if ($actor->tenantBucket !== null) {
            $buckets[$actor->tenantBucket] = $this->limits->tenant;
        }

        if ($actor->userBucket !== null) {
            $buckets[$actor->userBucket] = $this->limits->user;
        }

        if ($actor->terminalBucket !== null) {
            $buckets[$actor->terminalBucket] = $this->limits->terminal;
        }

        if ($actor->ipBucket !== null) {
            $buckets[$actor->ipBucket] = $this->limits->unauthenticated;
        }

        return $buckets;
    }

    /**
     * @param  array<string, int>  $buckets
     * @return array{0: int, 1: int}
     */
    private function tightest(array $buckets): array
    {
        $limit = 0;
        $remaining = 0;
        $tightestRemaining = null;

        foreach ($buckets as $bucket => $bucketLimit) {
            $bucketRemaining = max(0, $bucketLimit - $this->limiter->attempts($bucket));

            if ($tightestRemaining === null || $bucketRemaining < $tightestRemaining) {
                $tightestRemaining = $bucketRemaining;
                $limit = $bucketLimit;
                $remaining = $bucketRemaining;
            }
        }

        return [$limit, $remaining];
    }
}

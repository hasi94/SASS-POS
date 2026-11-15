<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Flexipos\Contracts\Platform\PlanLimitReached;
use Illuminate\Http\JsonResponse;

/**
 * The one refusal shape a plan limit answers with (T-016 Ruling 11).
 *
 * Rendered once, from `bootstrap/app.php`'s `withExceptions`: controllers
 * stay unchanged and catch only their own package's refusal, so
 * `PlanLimitReached` propagates here.
 */
final class PlatformRefusals
{
    public static function planLimitReached(PlanLimitReached $reached): JsonResponse
    {
        return new JsonResponse([
            'error' => 'plan_limit_reached',
            'limit_key' => $reached->limitKey()->value,
            'limit' => $reached->limit(),
        ], 403);
    }
}

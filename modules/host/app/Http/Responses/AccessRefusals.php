<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Http\JsonResponse;

/**
 * Every refusal the access endpoints and their guard answer with, built in
 * one place.
 *
 * **One producer, for {@see AuthRefusals}'s reason.** Nineteen controllers
 * and one middleware could agree today about what a refused request looks
 * like and drift tomorrow; they do not compose a body, they call a method
 * here. The body is always `{"error": "<reason>"}` and the reason is always
 * a member of the closed set (D-26), so a criterion can assert the cause
 * rather than the status class - which is the whole point of closing the
 * set.
 *
 * **Two statuses, and the line between them is what the caller can fix.**
 * `validation` is 422: the submission was unreadable or malformed, and the
 * remedy is a corrected field. Everything else is 403: the request was
 * understood and the shop's rules refused it, and no amount of re-sending
 * will change that - the remedy is a different operation (attach the
 * branch, assign a second owner, use a custom role).
 *
 * **The reason reaching the caller is never finer than the set.**
 * `not_granted`, `explicit_deny` and `out_of_scope` live in
 * `access_log.detail.reason` and are not members of `RefusalReason`, so
 * there is no value this class could be handed that would leak one.
 */
final class AccessRefusals
{
    /**
     * The 403 every guarded route answers when the employee may not do it
     * (AC-64).
     *
     * The body says `not_permitted` for all three finer causes, and this is
     * the only method the guard calls, so the three cannot be told apart at
     * the wire even by accident.
     */
    public static function notPermitted(): JsonResponse
    {
        return self::of(RefusalReason::NotPermitted);
    }

    /**
     * The 401 a stale tenant token gets (D-7, AC-66).
     *
     * **401 and not 403, and the line is what the caller should do next.**
     * A 403 says "you may not do this"; here the caller may well be
     * entitled to do it and is holding a credential that no longer
     * describes them. The remedy is to refresh - which is what a 401 asks a
     * client for - and every client already has that path for an expired
     * token. A 403 would send a correct caller to an administrator.
     *
     * It is also the one refusal in this class that is **not** a decision
     * about grants, which is why it writes no `access_log` row: a stale
     * credential is not a denial (AC-66, AC-69).
     */
    public static function permissionsChanged(): JsonResponse
    {
        return new JsonResponse(['error' => RefusalReason::PermissionsChanged->value], 401);
    }

    /**
     * A refusal a handler threw, rendered with its own reason.
     */
    public static function refused(AccessRefused $refused): JsonResponse
    {
        return self::of($refused->reason);
    }

    /**
     * One reason, as the response that carries it.
     */
    public static function of(RefusalReason $reason): JsonResponse
    {
        return new JsonResponse(
            ['error' => $reason->value],
            $reason === RefusalReason::Validation ? 422 : 403,
        );
    }
}

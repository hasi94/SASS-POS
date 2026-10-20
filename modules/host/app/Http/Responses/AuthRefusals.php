<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Flexipos\Auth\Exceptions\EmployeeUnavailable;
use Flexipos\Auth\Exceptions\NoTenantAccess;
use Flexipos\Auth\Exceptions\TenantNotServable;
use Flexipos\Auth\Support\GenericRefusal;
use Illuminate\Http\JsonResponse;

/**
 * Every refusal the authentication endpoints and their middleware can answer
 * with, built in one place.
 *
 * **Why this class exists at all.** `Flexipos\Auth\Support\GenericRefusal`
 * shipped in step 9 with no production caller, deliberately: D-12's "one
 * status, one body, byte-identical across causes" is a property of having one
 * producer, and the four controllers plus two middleware that arrived in step
 * 12 are six places that could have agreed today and drifted tomorrow. They do
 * not compose a body; they call a method here, and every method here that
 * answers a credential refusal returns the bytes `GenericRefusal` produced.
 *
 * {@see JsonResponse::fromJsonString()} rather than an array, for the same
 * reason: `GenericRefusal::json()` is the one encoder, so "byte-identical on
 * the wire" is the same fact as "byte-identical in the package", and not a
 * second encoding that happens to agree.
 *
 * **The four shapes, and why each is a separate one.**
 *
 * - 401 `invalid_credentials` - the caller has not proved who they are. Wrong
 *   password, unknown email, a locked or inactive account, a missing,
 *   malformed, unknown-key, retired-key or expired token: all one answer
 *   (AC-6, AC-42's 401 half, AC-46's 401 half). The cause survives in
 *   `login_history.failure_reason` or in the log, never here.
 * - 403 `wrong_token_type` - the caller holds a token this system issued and
 *   presented it where the other kind is required (AC-42, AC-46). It is not
 *   401 because authenticating again would not help and the client's remedy
 *   is to exchange the token it already holds; telling it so discloses
 *   nothing it did not already have.
 * - 403 `no_access` / `tenant_not_servable` - the caller *is* authenticated
 *   and the answer is about a shop (AC-40). Both bodies are the exception's
 *   own, so the status name in (b) cannot drift from the status the handler
 *   refused on.
 * - 422 - the request could not be read at all (AC-9, AC-41). The reason is
 *   about the shape of the submission, never about an account, so naming it
 *   is safe and is what lets a client fix its own call.
 * - 503 - no key can sign (AC-31). An operational failure, not a credential
 *   one: `SigningKeyring` has already logged it at error level, which is the
 *   trace AC-31 requires, and this is the 5xx-class refusal it asks the
 *   client for. No `Retry-After`: the header is a promise that waiting helps,
 *   and `bootstrap/app.php` already draws that line between a tenant the
 *   runner is about to migrate and a deployment nobody configured.
 */
final class AuthRefusals
{
    /**
     * The error token of the 403 in AC-42 and AC-46.
     */
    public const WRONG_TOKEN_TYPE = 'wrong_token_type';

    /**
     * The error token of the 422 in AC-9 and AC-41.
     */
    public const INVALID_REQUEST = 'invalid_request';

    /**
     * The error token of the 503 in AC-31.
     */
    public const SIGNING_UNAVAILABLE = 'signing_unavailable';

    /**
     * D-12's one refusal, rendered as the bytes `GenericRefusal` produced.
     */
    public static function credentials(): JsonResponse
    {
        return JsonResponse::fromJsonString(GenericRefusal::json(), GenericRefusal::STATUS);
    }

    /**
     * A tenant token where a platform one is required, or the reverse.
     */
    public static function wrongTokenType(): JsonResponse
    {
        return new JsonResponse(['error' => self::WRONG_TOKEN_TYPE], 403);
    }

    /**
     * AC-40(a): every shape of "that shop is not yours" is this one answer.
     */
    public static function noAccess(): JsonResponse
    {
        return new JsonResponse(NoTenantAccess::body(), NoTenantAccess::STATUS);
    }

    /**
     * AC-40(b): the shop exists and the membership is good, but its
     * commercial status is not selectable. The status travels with the
     * refusal because the caller is entitled to know why their own shop will
     * not open.
     */
    public static function tenantNotServable(TenantNotServable $refusal): JsonResponse
    {
        return new JsonResponse($refusal->body(), TenantNotServable::STATUS);
    }

    /**
     * T-010 AC-61: the membership names an employee the shop cannot serve.
     *
     * A third "you are known and the answer is still no", beside
     * `no_access` and `tenant_not_servable`. One body for D-6's four
     * causes; the cause is in the application log and in
     * `login_history.failure_reason`, never here.
     */
    public static function employeeUnavailable(): JsonResponse
    {
        return new JsonResponse(EmployeeUnavailable::refused()->body(), EmployeeUnavailable::STATUS);
    }

    /**
     * AC-9 and AC-41: the submission could not be read.
     */
    public static function invalidRequest(string $reason): JsonResponse
    {
        return new JsonResponse(['error' => self::INVALID_REQUEST, 'reason' => $reason], 422);
    }

    /**
     * AC-31: no active signing key, so nothing can be issued.
     */
    public static function signingUnavailable(): JsonResponse
    {
        return new JsonResponse(['error' => self::SIGNING_UNAVAILABLE], 503);
    }
}

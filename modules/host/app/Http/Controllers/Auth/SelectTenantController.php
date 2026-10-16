<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePlatformToken;
use App\Http\Requests\AuthRequestContext;
use App\Http\Responses\AuthRefusals;
use Flexipos\Auth\Exceptions\InvalidTenantCode;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Exceptions\NoTenantAccess;
use Flexipos\Auth\Exceptions\TenantNotServable;
use Flexipos\Auth\Features\SelectTenant\SelectTenant;
use Flexipos\Auth\Features\SelectTenant\SelectTenantHandler;
use Flexipos\Auth\Features\SelectTenant\TenantCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/tenant` - a shop code in, a tenant token out.
 *
 * **The subject is the token's.** `userId` comes from the request attribute
 * {@see RequirePlatformToken::USER_ID}, which the middleware set from the
 * verified `sub` claim. The body is read for the shop code and for nothing
 * else; a body naming a user is not consulted, and there is no path here by
 * which it could be.
 *
 * The route carries {@see RequirePlatformToken}, so AC-42's three refusals -
 * a tenant token, an expired platform token, no token - are answered before
 * this class is constructed. It never sees them.
 *
 * As in {@see LoginController}, the transport validation here is a second
 * copy of the slice's and the slice's is the one that must stay.
 */
final class SelectTenantController extends Controller
{
    public function __construct(private readonly SelectTenantHandler $selection) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = AuthRequestContext::from($request);
        $code = $request->input('code');

        try {
            // AC-41, before the handler.
            TenantCodeInput::from($code);

            $result = $this->selection->handle(new SelectTenant(
                userId: (string) $request->attributes->get(RequirePlatformToken::USER_ID),
                code: $code,
                ip: $context->ip,
                userAgent: $context->userAgent,
                deviceId: $context->deviceId,
            ));
        } catch (InvalidTenantCode $invalid) {
            return AuthRefusals::invalidRequest($invalid->reason());
        } catch (NoTenantAccess) {
            // AC-40(a): one answer for an unknown code, a missing membership,
            // an `invited` or `suspended` one, and a soft-deleted shop, so
            // that other people's shops are not enumerable.
            return AuthRefusals::noAccess();
        } catch (TenantNotServable $refusal) {
            // AC-40(b): the caller's own shop, and the reason is theirs.
            return AuthRefusals::tenantNotServable($refusal);
        } catch (NoActiveSigningKey) {
            return AuthRefusals::signingUnavailable();
        }

        return new JsonResponse($result->toResponse(), 200);
    }
}

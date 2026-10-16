<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePlatformToken;
use App\Http\Requests\AuthRequestContext;
use App\Http\Responses\AuthRefusals;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Exceptions\NoTenantAccess;
use Flexipos\Auth\Exceptions\TenantNotServable;
use Flexipos\Auth\Features\RefreshToken\RefreshToken;
use Flexipos\Auth\Features\RefreshToken\RefreshTokenHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/refresh` - a refresh token in the body, a rotated pair
 * out.
 *
 * **No middleware, and that is the design.** The refresh token *is* the
 * credential, and it is presented in the body rather than as a bearer token
 * precisely because it is not an access token: a client whose access token
 * has expired must still be able to call this. There is nothing for
 * {@see RequirePlatformToken} to verify here.
 *
 * **No transport validation either, and that is not an omission.** AC-34 to
 * AC-36 make a missing, non-string, unknown, expired, revoked and replayed
 * token one answer: the generic refusal. A 422 for the non-string case would
 * be a second answer, and it would tell a caller that its value reached the
 * lookup - so the slice folds all of them into `CredentialRefused` and this
 * controller renders that one body. The 422 shape belongs to login and
 * selection, where the submission is a form and not a secret.
 *
 * The tenant re-check of AC-37 refuses with AC-40's two bodies and, by D-24,
 * does **not** consume the token; nothing here needs to know that, which is
 * the point of it being the slice's rule.
 */
final class RefreshController extends Controller
{
    public function __construct(private readonly RefreshTokenHandler $refresh) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = AuthRequestContext::from($request);

        try {
            $result = $this->refresh->handle(new RefreshToken(
                token: $request->input('refresh_token'),
                ip: $context->ip,
                userAgent: $context->userAgent,
                deviceId: $context->deviceId,
            ));
        } catch (CredentialRefused) {
            return AuthRefusals::credentials();
        } catch (NoTenantAccess) {
            return AuthRefusals::noAccess();
        } catch (TenantNotServable $refusal) {
            return AuthRefusals::tenantNotServable($refusal);
        } catch (NoActiveSigningKey) {
            return AuthRefusals::signingUnavailable();
        }

        return new JsonResponse($result->toResponse(), 200);
    }
}

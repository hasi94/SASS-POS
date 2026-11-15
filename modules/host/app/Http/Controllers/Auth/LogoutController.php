<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequestContext;
use Flexipos\Auth\Features\Logout\Logout;
use Flexipos\Auth\Features\Logout\LogoutHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/logout` - one answer, always (AC-49).
 *
 * A valid token revokes its whole family; an unknown, expired or already
 * revoked one changes nothing. Both return the same 200 and the same body,
 * so the endpoint discloses nothing about which values were ever real. There
 * is no refusal to render here and therefore no `catch`: the handler has no
 * refusal to throw.
 *
 *
 * **Every submitted value is read with {@see AuthRequestContext::body()},
 * never with `$request->input()`**, which merges the query string: R-1.
 * **It resolves no signing key, and no middleware guards it.** Both are
 * deliberate and they are the same decision. Signing out mints nothing, so a
 * key is not needed - and a deployment whose every key has been retired must
 * still be able to sign a lost device out, which is exactly when an operator
 * needs revocation most. Requiring a platform token here would be the same
 * mistake from the other direction: a client whose access token has expired
 * would be unable to end its own session.
 */
final class LogoutController extends Controller
{
    public function __construct(private readonly LogoutHandler $logout) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = AuthRequestContext::body($request, 'refresh_token');

        $result = $this->logout->handle(new Logout($token));

        return new JsonResponse($result->toResponse(), 200);
    }
}

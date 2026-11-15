<?php

declare(strict_types=1);

namespace App\Http\Controllers\Terminal;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequestContext;
use App\Http\Responses\AuthRefusals;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Features\RefreshTerminalToken\RefreshTerminalToken;
use Flexipos\Auth\Features\RefreshTerminalToken\RefreshTerminalTokenHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/terminal/refresh` - a terminal's refresh token for its next
 * pair (T-014 AC-36).
 *
 * The token is a credential and is read from the body only; a copy in the
 * query string is never read, so it cannot be accepted by accident. Every
 * refusal is the one uniform body, and no cause - a replay, a revoked
 * terminal, a paused one, an expired token - is distinguishable from another.
 */
final class RefreshTerminalTokenController extends Controller
{
    public function __construct(private readonly RefreshTerminalTokenHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = AuthRequestContext::from($request);

        try {
            $result = $this->handler->handle(new RefreshTerminalToken(
                AuthRequestContext::body($request, 'refresh_token'),
                $context->ip,
                $context->userAgent,
            ));
        } catch (CredentialRefused) {
            return AuthRefusals::credentials();
        } catch (NoActiveSigningKey) {
            return AuthRefusals::signingUnavailable();
        }

        return new JsonResponse($result->toResponse(), 200);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Terminal;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequestContext;
use App\Http\Responses\AuthRefusals;
use Flexipos\Auth\Exceptions\ActivationUnavailable;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Features\ActivateTerminal\ActivateTerminal;
use Flexipos\Auth\Features\ActivateTerminal\ActivateTerminalHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/terminal/activate` - the public route a till calls once, with
 * its activation code in the body (T-014 AC-29, AC-30, AC-31, AC-27).
 *
 * **The body is the only input channel.** The code is a credential, and a
 * credential in a URL is written to the web server's access log before the
 * application sees it (T-009's rule for passwords, T-010's for PINs). So
 * every field is read with {@see AuthRequestContext::body()}, which never
 * reads the query string, and a request carrying `code` or `activation_code`
 * in its query string is refused with the same uniform refusal, not echoed and
 * not logged.
 *
 * **Every refusal is `AuthRefusals::credentials()`: the same bytes, no
 * `Retry-After`**, so a caller cannot tell an expired code from a wrong tenant
 * from a locked address. The two operational failures are the only different
 * answers, and both are 503s that say nothing about the code.
 */
final class ActivateTerminalController extends Controller
{
    public function __construct(private readonly ActivateTerminalHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($request->query->has('code') || $request->query->has('activation_code')) {
            return AuthRefusals::credentials();
        }

        $context = AuthRequestContext::from($request);

        try {
            $result = $this->handler->handle(new ActivateTerminal(
                code: AuthRequestContext::body($request, 'code'),
                fingerprint: AuthRequestContext::body($request, 'fingerprint'),
                platform: AuthRequestContext::body($request, 'platform'),
                browser: AuthRequestContext::body($request, 'browser'),
                os: AuthRequestContext::body($request, 'os'),
                screen: AuthRequestContext::body($request, 'screen'),
                ip: $context->ip,
                userAgent: $context->userAgent,
            ));
        } catch (CredentialRefused) {
            return AuthRefusals::credentials();
        } catch (NoActiveSigningKey) {
            return AuthRefusals::signingUnavailable();
        } catch (ActivationUnavailable) {
            return AuthRefusals::activationUnavailable();
        }

        return new JsonResponse($result->toResponse(), 200);
    }
}

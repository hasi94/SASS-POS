<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequestContext;
use App\Http\Responses\AuthRefusals;
use Flexipos\Auth\Exceptions\CredentialRefused;
use Flexipos\Auth\Exceptions\InvalidLoginInput;
use Flexipos\Auth\Exceptions\NoActiveSigningKey;
use Flexipos\Auth\Features\Login\Login;
use Flexipos\Auth\Features\Login\LoginCredentials;
use Flexipos\Auth\Features\Login\LoginHandler;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/login` - credentials in, a platform token out.
 *
 * Thin by rule: it reads the request, refuses what it cannot read, calls the
 * one handler and renders. It holds no credential rule - which account is
 * active, how many failures lock one, whether an email matches - and it never
 * composes a refusal body of its own (see {@see AuthRefusals}).
 *
 * **It validates, and so does the slice, and the duplication is deliberate.**
 * {@see LoginCredentials::from()} is called here against the raw request
 * values and again inside {@see LoginHandler}. The slice's copy is the one
 * that matters: AC-9's absences are properties of *position* - the hasher is
 * not invoked and no `login_history` row is written - and a caller that is
 * not this controller (a console command, a future module) must get the same
 * refusal. The controller's copy is defence in depth and is honestly not
 * independently observable through HTTP, because both sites refuse the same
 * input the same way; removing either one leaves the endpoint's behaviour
 * unchanged, and it is the slice's that must never be the one removed.
 *
 *
 * **Every submitted value is read with {@see AuthRequestContext::body()},
 * never with `$request->input()`**, which merges the query string: R-1.
 * **No catch for anything else.** An unexpected throw is a 500 with a logged
 * trace, which is what an unexpected throw should be. The three caught here
 * are the three the handler documents.
 */
final class LoginController extends Controller
{
    public function __construct(
        private readonly LoginHandler $login,
        private readonly ConfigRepository $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = AuthRequestContext::from($request);
        $email = AuthRequestContext::body($request, 'email');
        $password = AuthRequestContext::body($request, 'password');

        try {
            // AC-9, before the handler: a request that cannot be read is not
            // a credential that was judged.
            LoginCredentials::from(
                $email,
                $password,
                (int) $this->config->get('authentication.limits.email'),
                (int) $this->config->get('authentication.limits.password'),
            );

            $result = $this->login->handle(new Login(
                email: $email,
                password: $password,
                ip: $context->ip,
                userAgent: $context->userAgent,
                deviceId: $context->deviceId,
            ));
        } catch (InvalidLoginInput $invalid) {
            return AuthRefusals::invalidRequest($invalid->reason());
        } catch (CredentialRefused) {
            // D-12: one answer for all five causes. The cause is in
            // `login_history.failure_reason`, which the handler has written.
            return AuthRefusals::credentials();
        } catch (NoActiveSigningKey) {
            // AC-31. The keyring has already logged this at error level.
            return AuthRefusals::signingUnavailable();
        }

        return new JsonResponse($result->toResponse(), 200);
    }
}

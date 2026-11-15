<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RefreshController;
use App\Http\Controllers\Auth\SelectTenantController;
use App\Http\Controllers\Terminal\ActivateTerminalController;
use App\Http\Controllers\Terminal\RefreshTerminalTokenController;
use App\Http\Middleware\RequirePlatformToken;
use Illuminate\Support\Facades\Route;

/*
 * The four authentication endpoints, mounted by AuthServiceProvider.
 *
 * The shape is the one modules/demo/routes/api.php established: the `api`
 * middleware group, an explicit `api/v1/...` prefix, and an invokable
 * controller for every route - never an inline closure (T-004 AC-13).
 *
 * **Only /tenant carries a middleware.** Login takes credentials, refresh
 * takes a refresh token in the body and logout must work with no valid token
 * at all (AC-49); none of the three has an access token to verify. Selection
 * is the one operation performed *as* an already-authenticated person, so it
 * is the one that requires a platform token - and AC-42's three refusals are
 * the middleware's, answered before the controller is built.
 *
 * **No tenant-scoped route is declared here**, and that is not an oversight:
 * RequireTenantToken guards the routes that *modules* will declare, and
 * authentication owns none of them. This file would be the wrong place for
 * the first one.
 *
 * This is the whole HTTP surface of T-009. FP-215's API envelope, error-code
 * catalogue, idempotency store and rate limiter are not built here; these
 * four endpoints use architecture.md section 8's shape and nothing more.
 */

Route::middleware('api')
    ->prefix('api/v1/auth')
    ->group(function (): void {
        Route::post('/login', LoginController::class)->name('auth.login');

        Route::post('/tenant', SelectTenantController::class)
            ->middleware(RequirePlatformToken::class)
            ->name('auth.tenant');

        Route::post('/refresh', RefreshController::class)->name('auth.refresh');

        Route::post('/logout', LogoutController::class)->name('auth.logout');
    });

/*
 * The terminal's two public routes (T-014 AC-29, AC-36), in a group of their
 * own because they are not authentication of a person.
 *
 * **No middleware beyond `api`, and the body is the only input channel**: an
 * activation code and a refresh token are credentials, and neither has an
 * access token to verify when it arrives. Activation is the one place a
 * terminal's identity is created; refresh is how it stays alive. Both answer
 * every refusal with the one uniform body.
 */

Route::middleware('api')
    ->prefix('api/v1/terminal')
    ->group(function (): void {
        Route::post('/activate', ActivateTerminalController::class)->name('terminal.activate');

        Route::post('/refresh', RefreshTerminalTokenController::class)->name('terminal.refresh');
    });

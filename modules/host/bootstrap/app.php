<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\RequirePlatformToken;
use App\Http\Middleware\RequireTenantToken;
use App\Http\Middleware\RequireTerminalToken;
use App\Http\Middleware\ThrottleApi;
use App\Http\Responses\ApiFailureRenderer;
use App\Http\Responses\PlatformRefusals;
use Flexipos\Contracts\Platform\PlanLimitReached;
use Flexipos\Kernel\Http\RequestId;
use Flexipos\Kernel\Tenancy\Exceptions\RequiredSchemaVersionNotConfigured;
use Flexipos\Kernel\Tenancy\Exceptions\TenantSchemaBehind;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // routes/health.php is loaded here, not passed as `web:`, so that
        // /health/live and /health/ready are registered outside every
        // middleware group - the same way the framework registers the `health`
        // route above. See routes/health.php for why that matters.
        then: function (): void {
            require __DIR__.'/../routes/health.php';
            require __DIR__.'/../routes/api.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // T-017 Ruling 1: every request gets its own correlation id before
        // anything else runs, so any audit row a request causes can carry
        // it. Prepended, not appended: it must run ahead of every handler
        // that might write one.
        $middleware->prepend(AssignRequestId::class);

        // T-021 Ruling 5 (AC-27 to AC-33b). Appended to the `api` group, so
        // every api/v1/* route gets it, including every existing route and
        // the demo ping. /health/* and /up are in no group, so they never
        // touch it (AC-33a).
        $middleware->api(append: [ThrottleApi::class]);

        // The three token middleware are route-level, not group-level, so
        // the limiter needs the identity they set - this is what orders
        // ThrottleApi after whichever of them a route uses, through
        // Laravel's own SortedMiddleware, without editing a single route
        // file. SubstituteBindings still runs first; RequireIdempotencyKey
        // joins this list in step 10, after ThrottleApi, once it exists.
        $middleware->appendToPriorityList(SubstituteBindings::class, RequireTenantToken::class);
        $middleware->appendToPriorityList(SubstituteBindings::class, RequireTerminalToken::class);
        $middleware->appendToPriorityList(SubstituteBindings::class, RequirePlatformToken::class);
        $middleware->appendToPriorityList(
            [RequireTenantToken::class, RequireTerminalToken::class, RequirePlatformToken::class],
            ThrottleApi::class
        );
        $middleware->appendToPriorityList(ThrottleApi::class, RequireIdempotencyKey::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A plan limit is a business refusal, not an error (T-016 Ruling 11):
        // it is `dontReport`, and it is rendered here rather than by the
        // controllers it fires inside of, because every one of those
        // controllers catches only its own package's refusal exception and
        // a `PlanLimitReached` is deliberately a different type that
        // propagates straight past that catch to here.
        $exceptions->dontReport(PlanLimitReached::class);

        $exceptions->render(fn (PlanLimitReached $reached) => PlatformRefusals::planLimitReached($reached));

        // A tenant whose schema is behind this code is not a client error and
        // not a server fault: it is a state that passes, and the migration
        // runner is what clears it. 503 with Retry-After is what says that to a
        // browser, a till and a monitor alike (AC-19).
        //
        // **The body is a fixed sentence; the detail goes to the log** (R-W2).
        // The exception's own message carries the tenant id and both version
        // numbers. It carries no credential - the version gate runs before the
        // credential reference is dereferenced, so there is nothing in it to
        // redact - but it is still operational detail about a deployment, and
        // this response reaches anyone who can make a request. An operator
        // reads the log; a till reads the status code.
        $exceptions->render(function (TenantSchemaBehind $behind, Request $request) {
            $retryAfter = (string) max(1, (int) config('tenancy.behind_retry_after', 30));

            Log::warning($behind->getMessage());

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => TenantSchemaBehind::PUBLIC_MESSAGE,
                ], 503, ['Retry-After' => $retryAfter]);
            }

            return response(TenantSchemaBehind::PUBLIC_MESSAGE, 503, ['Retry-After' => $retryAfter]);
        });

        // A floor nobody configured is a misconfiguration, and it is 503
        // **without** Retry-After (step 7a, ruling 13.1). The header above is a
        // promise that waiting helps, which is true of a tenant the runner is
        // about to migrate and false of a missing environment variable: a till
        // that retried forever against one would be a second incident. Its
        // absence is what makes the two states distinguishable at the wire and
        // not only in a log.
        //
        // **The body is a fixed sentence** (R-W2), and here the reason is
        // sharper than above: the message names the configuration key that
        // fixes the deployment, so returning it verbatim hands an
        // unauthenticated client the remedy for a server it does not own. The
        // key and the tenant go to the log.
        $exceptions->render(function (RequiredSchemaVersionNotConfigured $unconfigured, Request $request) {
            Log::warning($unconfigured->getMessage());

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => RequiredSchemaVersionNotConfigured::PUBLIC_MESSAGE,
                ], 503);
            }

            return response(RequiredSchemaVersionNotConfigured::PUBLIC_MESSAGE, 503);
        });

        // T-021 Ruling 4, AC-6 to AC-8, AC-48, AC-49. Registered last, so
        // every render callback above and every controller/middleware body
        // that already answers a refusal (AuthRefusals, AccessRefusals,
        // OrganizationRefusals) keeps winning for the cause it handles -
        // Laravel tries render callbacks in registration order and the
        // first non-null answer wins. Only a failure none of them already
        // handled, on an api/* path, reaches this one.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! str_starts_with($request->path(), 'api/') || $e instanceof HttpResponseException) {
                return null;
            }

            return ApiFailureRenderer::render($e);
        });

        // AC-8: every reported exception carries the request id, so the log
        // line a 500's generic body points to can be found by it. No second
        // log line is written here - the framework's own reporting already
        // happens.
        //
        // **Never lets a context failure become the reported error.** An
        // exception can be thrown while providers are still registering
        // (ModuleHostServiceProvider's own guards do exactly this), before
        // Flexipos\Kernel\Tenancy\TenancyServiceProvider has bound
        // RequestId at all - resolving it unguarded would replace the
        // guard's own message with a binding-resolution error instead of
        // reporting it.
        $exceptions->context(function (): array {
            try {
                return array_filter(['request_id' => app(RequestId::class)->current()]);
            } catch (Throwable) {
                return [];
            }
        });
    })->create();

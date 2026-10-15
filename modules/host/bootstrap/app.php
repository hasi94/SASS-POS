<?php

use Flexipos\Kernel\Tenancy\Exceptions\RequiredSchemaVersionNotConfigured;
use Flexipos\Kernel\Tenancy\Exceptions\TenantSchemaBehind;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A tenant whose schema is behind this code is not a client error and
        // not a server fault: it is a state that passes, and the migration
        // runner is what clears it. 503 with Retry-After is what says that to a
        // browser, a till and a monitor alike (AC-19).
        //
        // The message is the exception's own, which carries the tenant id and
        // the two version numbers and no credential - the version gate runs
        // before the credential reference is dereferenced, so there is nothing
        // in it to redact.
        $exceptions->render(function (TenantSchemaBehind $behind, Request $request) {
            $retryAfter = (string) max(1, (int) config('tenancy.behind_retry_after', 30));

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $behind->getMessage(),
                ], 503, ['Retry-After' => $retryAfter]);
            }

            return response($behind->getMessage(), 503, ['Retry-After' => $retryAfter]);
        });

        // A floor nobody configured is a misconfiguration, and it is 503
        // **without** Retry-After (step 7a, ruling 13.1). The header above is a
        // promise that waiting helps, which is true of a tenant the runner is
        // about to migrate and false of a missing environment variable: a till
        // that retried forever against one would be a second incident. Its
        // absence is what makes the two states distinguishable at the wire and
        // not only in a log.
        $exceptions->render(function (RequiredSchemaVersionNotConfigured $unconfigured, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $unconfigured->getMessage(),
                ], 503);
            }

            return response($unconfigured->getMessage(), 503);
        });
    })->create();

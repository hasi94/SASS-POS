<?php

use Flexipos\Kernel\Tenancy\Exceptions\RequiredSchemaVersionNotConfigured;
use Flexipos\Kernel\Tenancy\Exceptions\TenantDatabaseNotMapped;
use Flexipos\Kernel\Tenancy\Exceptions\TenantSchemaBehind;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| A behind tenant is a 503 with Retry-After
|--------------------------------------------------------------------------
|
| T-008 step 7, the rendering half of AC-19. The refusal itself is proven in
| packages/kernel/tests/Feature/TenancyServingVersionGateTest.php; what is
| asserted here is the wiring in bootstrap/app.php, which nothing else in the
| branch would notice if it were removed.
|
| The exception is handed to the application's own handler rather than driven
| through a route: there is no tenant-scoped route yet - routing a request
| through a tenant is FP-206's - and a test that added one would be asserting
| its own route instead of the host's exception configuration.
|
| 503 and not 500: a tenant whose schema is behind this code is neither a client
| error nor a server fault. It is a state that passes, and the migration runner
| is what clears it, which is exactly what Retry-After says to a browser, a till
| and a monitor alike.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

it('renders a behind tenant as 503 with Retry-After, for a browser and for an API client', function () {
    $behind = TenantSchemaBehind::forTenant('01J0000000000000000000000A', 5, 6);

    $handler = app(ExceptionHandler::class);

    $html = $handler->render(Request::create('/pos/sale', 'GET'), $behind);

    expect($html->getStatusCode())->toBe(503)
        ->and($html->headers->get('Retry-After'))->toBe((string) config('tenancy.behind_retry_after'))
        ->and((string) config('tenancy.behind_retry_after'))->toBe('30');

    $json = $handler->render(
        Request::create('/pos/sale', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
        $behind
    );

    expect($json->getStatusCode())->toBe(503)
        ->and($json->headers->get('Retry-After'))->toBe('30')
        ->and((string) $json->getContent())->toContain('schema version');

    // The message names the tenant and both versions, and carries no
    // credential: the gate runs before the credential reference is
    // dereferenced, so there is nothing in it to redact.
    foreach ([$html, $json] as $response) {
        expect((string) $response->getContent())->toContain('01J0000000000000000000000A');
    }
});

it('renders an unconfigured floor as 503 without Retry-After, so it is not read as a running migration', function () {
    // T-008 step 7a. Both states are 503 - neither is a client error and
    // neither is a server fault - and the header is the whole difference: a
    // behind tenant's Retry-After promises that waiting helps, which is false
    // of a missing environment variable. A till that retried forever against
    // one would be a second incident, so the two are distinguishable at the
    // wire and not only in a log.
    $unconfigured = RequiredSchemaVersionNotConfigured::forTenant('01J0000000000000000000000A');

    $handler = app(ExceptionHandler::class);

    foreach ([[], ['HTTP_ACCEPT' => 'application/json']] as $server) {
        $response = $handler->render(Request::create('/pos/sale', 'GET', server: $server), $unconfigured);

        expect($response->getStatusCode())->toBe(503)
            ->and($response->headers->get('Retry-After'))->toBeNull()
            // The message names the variable an operator has to set and the
            // tenant that was refused, and carries no credential: the floor is
            // consulted before the credential reference is dereferenced.
            ->and((string) $response->getContent())->toContain('APP_REQUIRED_SCHEMA_VERSION')
            ->and((string) $response->getContent())->toContain('01J0000000000000000000000A');
    }

    // The control, and it is the point of this case: the *behind* exception
    // rendered through the same handler in the same process does carry the
    // header. Without it, a handler that had simply stopped setting Retry-After
    // at all would pass above.
    expect($handler->render(
        Request::create('/pos/sale', 'GET'),
        TenantSchemaBehind::forTenant('01J0000000000000000000000A', 5, 6)
    )->headers->get('Retry-After'))->toBe('30');
});

it('renders an ordinary tenancy refusal as 500, so the 503 is about the version gate', function () {
    // The control. Without it, a handler that answered 503 for everything would
    // pass the case above - and a tenant with no mapping at all is not a state
    // that passes, so it must not come back as "try again in thirty seconds".
    $response = app(ExceptionHandler::class)->render(
        Request::create('/pos/sale', 'GET'),
        TenantDatabaseNotMapped::forTenant('01J0000000000000000000000B')
    );

    expect($response->getStatusCode())->toBe(500)
        ->and($response->headers->get('Retry-After'))->toBeNull();
});

<?php

use Flexipos\Kernel\Tenancy\Exceptions\TenantSchemaBehind;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * T-021 Ruling 4: the generic api/* failure renderer. AC-4, AC-6, AC-7, AC-8,
 * AC-48 (sentinel), AC-49.
 *
 * Every route here is test-only, registered inside the test under
 * api/v1/qa-render/*, and never exists in production.
 */

beforeEach(function () {
    Route::post('api/v1/qa-render/validate', function (Request $request) {
        $request->validate(['a.b' => 'required', 'c' => 'required']);

        return response()->json(['ok' => true]);
    });

    Route::get('api/v1/qa-render/throw', function () {
        throw new RuntimeException('SELECT secret FROM x at C:\\path');
    });

    Route::get('api/v1/qa-render/schema-behind', function () {
        throw TenantSchemaBehind::forTenant('t1', 1, 2);
    });

    Route::get('api/v1/qa-render/only-get', fn () => response()->json(['ok' => true]));

    Route::get('web-render/throw', function () {
        throw new RuntimeException('web failure');
    });

    app('router')->getRoutes()->refreshNameLookups();
});

it('renders a 404 as the envelope with the catalogue code (AC-6)', function () {
    $response = getJson('/api/v1/qa-render/no-such-route');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->json('success'))->toBeFalse()
        ->and($response->json('errors.0.code'))->toBe('kernel.not_found');
});

it('renders a 405 as the envelope and keeps the Allow header (AC-6)', function () {
    $response = postJson('/api/v1/qa-render/only-get', []);

    expect($response->getStatusCode())->toBe(405)
        ->and($response->json('errors.0.code'))->toBe('kernel.method_not_allowed')
        ->and($response->headers->has('Allow'))->toBeTrue();
});

it('renders a validation failure with one item per field, dot notation (AC-7)', function () {
    $response = postJson('/api/v1/qa-render/validate', []);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toHaveCount(2);

    $fields = array_column($response->json('errors'), 'field');

    expect($fields)->toContain('a.b')->toContain('c');

    foreach ($response->json('errors') as $error) {
        expect($error['code'])->toBe('kernel.validation_failed');
    }
});

it('never discloses exception detail on a 500, even with debug on (AC-8)', function (bool $debug) {
    config()->set('app.debug', $debug);

    $response = getJson('/api/v1/qa-render/throw');
    $body = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(500)
        ->and($response->json('errors.0.code'))->toBe('kernel.server_error')
        ->and($body)->not->toContain('RuntimeException')
        ->and($body)->not->toContain('SELECT secret')
        ->and($body)->not->toContain('C:\\')
        ->and($body)->not->toContain('trace');
})->with(['debug on' => [true], 'debug off' => [false]]);

it('the body request id equals the X-Request-Id header (AC-4)', function () {
    $response = getJson('/api/v1/qa-render/no-such-route');

    expect($response->json('meta.request_id'))->toBe($response->headers->get('X-Request-Id'));
});

it('a web failure and an unknown non-api path render as the framework default, not the envelope (AC-49)', function () {
    config()->set('app.debug', false);

    $response = getJson('/web-render/throw');

    expect($response->json('success'))->toBeNull();

    $unknown = getJson('/no-such-web-path-at-all');

    expect($unknown->json('success'))->toBeNull();
});

it('the AC-48 sentinel: a registered render callback still wins over the generic renderer', function () {
    $response = getJson('/api/v1/qa-render/schema-behind');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->json('message'))->toBe(TenantSchemaBehind::PUBLIC_MESSAGE)
        ->and($response->json('success'))->toBeNull();
});

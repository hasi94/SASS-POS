<?php

use Illuminate\Support\Facades\Route;

it('reports liveness', function () {
    $this->bootHostWith('');

    $this->get('/health/live')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('reports exactly the booted service identifiers', function () {
    $this->bootHostWith('demo');

    $response = $this->get('/health/ready')->assertOk();

    $response->assertExactJson(['status' => 'ok', 'services' => ['demo']]);

    expect($response->json('services'))->toBe(['demo']);
});

it('reports an empty service list when the host booted with no services', function () {
    $this->bootHostWith('');

    $response = $this->get('/health/ready')->assertOk();

    $response->assertExactJson(['status' => 'ok', 'services' => []]);

    expect($response->json('services'))->toBe([]);
});

/*
 * T-002 FP-148 (T-001 review finding M-5).
 *
 * Both probes used to sit in routes/web.php and therefore inside the `web`
 * middleware group, so with the shipped SESSION_DRIVER=database StartSession
 * opened a database connection on every probe. A database outage then made
 * /health/live answer 500 and an orchestrator would kill a process that was
 * perfectly alive. They are registered outside every group now.
 */

it('answers liveness without touching the session store', function () {
    $this->bootHostWith('');

    // bootHostWith() calls refreshApplication(), which resets runtime config -
    // set this AFTER, not before, or the override is silently discarded and the
    // test proves nothing.
    config(['session.driver' => 'database']);

    // No migration ever runs in this suite, so a `sessions` table genuinely
    // does not exist. Inside the `web` group this would 500.
    $this->get('/health/live')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('answers readiness without touching the session store', function () {
    $this->bootHostWith('demo');

    config(['session.driver' => 'database']);

    $this->get('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'services' => ['demo']]);
});

it('registers both probes outside every middleware group', function (string $name) {
    $this->bootHostWith('');

    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toBe([]);
})->with(['health.live', 'health.ready']);

it('keeps the root route inside the web group', function () {
    // D-18: only the two health routes move. If this ever comes back empty, the
    // `then` callback has swallowed routes/web.php instead of adding to it.
    //
    // T-004 FP-157/D-39 retired the stock welcome route this used to probe. The
    // guarantee is unchanged and so is the shape of the assertion - only the
    // route on `/` changed, from a closure rendering a view to the invokable
    // RootController.
    $this->bootHostWith('');

    $root = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === '/');

    expect($root)->not->toBeNull()
        ->and($root?->gatherMiddleware())->toContain('web');
});

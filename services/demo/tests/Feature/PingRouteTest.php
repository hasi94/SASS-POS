<?php

use App\Support\ServiceHost\ServiceRegistry;
use Tests\TestCase;

use function Pest\Laravel\get;

/*
 * This package may not be listed in SERVICES yet, so the test boots it into the
 * host itself. That is the same code path the host uses at boot, which is why
 * this test passes straight after generation with no further edits.
 *
 * The HTTP calls use Pest's Laravel helper functions instead of $this->get():
 * Pest types a test closure's $this as the plain PHPUnit TestCase, so
 * $this->get() cannot be resolved by the static analysis that now covers every
 * services/<name>/tests directory at level 6.
 */

uses(TestCase::class);

beforeEach(function () {
    app(ServiceRegistry::class)->bootAll('demo');
});

it('answers the demo ping route under its own prefix', function () {
    get('/api/v1/demo/ping')
        ->assertOk()
        ->assertJson([
            'service' => 'demo',
            'status' => 'ok',
        ]);
});

it('registers the svc_demo database connection', function () {
    expect(config('database.connections.svc_demo'))->toBeArray()
        ->and(config('database.connections.svc_demo.database'))->toBe('pos_demo');
});

it('is reported by the readiness endpoint once booted', function () {
    expect(app(ServiceRegistry::class)->booted())->toContain('demo');

    get('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

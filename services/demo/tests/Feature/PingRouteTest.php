<?php

use App\Support\ServiceHost\ServiceRegistry;
use Tests\TestCase;

/*
 * This package may not be listed in SERVICES yet, so the test boots it into the
 * host itself. That is the same code path the host uses at boot, which is why
 * this test passes straight after generation with no further edits.
 */

uses(TestCase::class);

beforeEach(function () {
    app(ServiceRegistry::class)->bootAll('demo');
});

it('answers the demo ping route under its own prefix', function () {
    $this->get('/api/v1/demo/ping')
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

    $this->get('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

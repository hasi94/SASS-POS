<?php

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

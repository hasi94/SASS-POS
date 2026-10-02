<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

use function Pest\Laravel\get;

/*
 * QA proofs for T-002 AC-7 and AC-8.
 *
 * HealthEndpointTest (dev's) sets session.driver=database and relies on the
 * sessions table not existing. That is true today but it is a weaker claim than
 * the one AC-7 makes: an orchestrator must not kill a process whose database is
 * unreachable, which is a connection failure, not a missing table. These point
 * the default connection at a database that cannot be opened at all and, before
 * asserting the probes survive, prove the same configuration really does take a
 * session-backed route down - otherwise "it returned 200" would prove nothing
 * about the hazard.
 */

/**
 * The path the dead connection points at. Its parent directory is never
 * created, so PDO cannot open it however the driver is configured.
 */
function qaDeadDatabasePath(): string
{
    return storage_path('framework'.DIRECTORY_SEPARATOR.'qa-no-such-directory'.DIRECTORY_SEPARATOR.'dead.sqlite');
}

/**
 * Reconfigure the running application as if its database were down, with the
 * session driver the shipped .env.example actually ships.
 */
function qaBreakTheDatabase(): void
{
    config([
        'session.driver' => 'database',
        'session.connection' => 'qa_dead',
        'database.default' => 'qa_dead',
        'database.connections.qa_dead' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => qaDeadDatabasePath(),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);
}

it('ships SESSION_DRIVER=database, so this is the shipped configuration and not an invented one', function () {
    expect(File::get(base_path('.env.example')))->toContain("SESSION_DRIVER=database\n");
});

it('takes a session-backed route down when the database is unreachable', function () {
    /** @var TestCase $this */
    // The negative control for the two tests below. If this ever starts
    // answering 200, the "dead database" in them is not dead and they prove
    // nothing.
    $this->bootHostWith('demo');

    qaBreakTheDatabase();

    Route::middleware('web')->get('/qa-session-probe', fn (): string => 'ok');

    expect(File::exists(qaDeadDatabasePath()))->toBeFalse();

    get('/qa-session-probe')->assertStatus(500);
});

it('answers liveness with 200 while the database is unreachable', function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');

    qaBreakTheDatabase();

    $response = get('/health/live')->assertOk();

    expect($response->json())->toBe(['status' => 'ok'])
        ->and($response->headers->get('content-type'))->toContain('application/json');
});

it('answers readiness with the booted services while the database is unreachable', function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');

    qaBreakTheDatabase();

    get('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'services' => ['demo']]);
});

it('answers readiness with an empty list while the database is unreachable and no service is booted', function () {
    /** @var TestCase $this */
    $this->bootHostWith('');

    qaBreakTheDatabase();

    get('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'services' => []]);
});

it('sets no session cookie on either probe, so nothing can reintroduce the dependency', function () {
    /** @var TestCase $this */
    // A middleware-free route cannot start a session, and a probe that sets a
    // session cookie would be evidence one was started again.
    $this->bootHostWith('demo');

    foreach (['/health/live', '/health/ready'] as $uri) {
        $cookies = array_map(
            fn ($cookie): string => $cookie->getName(),
            get($uri)->assertOk()->baseResponse->headers->getCookies()
        );

        expect($cookies)->toBe([], $uri);
    }
});

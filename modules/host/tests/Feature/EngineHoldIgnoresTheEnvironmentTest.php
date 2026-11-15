<?php

use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineDatabase;
use Flexipos\Testing\Engine\EngineOnHold;

/*
|--------------------------------------------------------------------------
| T-008, BUG-2: the hold is not a variable anybody can unset
|--------------------------------------------------------------------------
|
| The hold used to be read from ENGINE_HOLD. MEASURED by qa on 2026-10-15:
| ENGINE_HOLD=0 in the shell beat phpunit.xml's declaration, because PHPUnit's
| <env> does not overwrite a variable that is already set and the reader
| preferred $_SERVER, which <env> never writes at all. The first step of
| `composer check` reads the same hold before any test runs, so the shell
| variable was one word away from a connection to the engine that lost sixteen
| of the owner's databases on 2026-10-14.
|
| The fix is a constant, EngineOnHold::HELD, and this file is its observable:
| every shape the old reader accepted as "cleared" is planted in all three
| places PHP exposes an environment variable, and the hold still holds. A
| reader that went back to the environment turns every case below red.
|
| Nothing here opens a connection: the assertions are that the two entry
| points throw before they would.
*/

/**
 * Run the callback with ENGINE_HOLD planted everywhere a reader could look,
 * and restore whatever was there afterwards.
 */
function withPlantedEngineHold(string $value, Closure $callback): void
{
    $server = $_SERVER['ENGINE_HOLD'] ?? null;
    $env = $_ENV['ENGINE_HOLD'] ?? null;
    $ambient = getenv('ENGINE_HOLD');

    $_SERVER['ENGINE_HOLD'] = $value;
    $_ENV['ENGINE_HOLD'] = $value;
    putenv('ENGINE_HOLD='.$value);

    try {
        $callback();
    } finally {
        if ($server === null) {
            unset($_SERVER['ENGINE_HOLD']);
        } else {
            $_SERVER['ENGINE_HOLD'] = $server;
        }

        if ($env === null) {
            unset($_ENV['ENGINE_HOLD']);
        } else {
            $_ENV['ENGINE_HOLD'] = $env;
        }

        if ($ambient === false) {
            putenv('ENGINE_HOLD');
        } else {
            putenv('ENGINE_HOLD='.$ambient);
        }
    }
}

it('stays on hold however ENGINE_HOLD is spelled in the environment', function (string $value) {
    withPlantedEngineHold($value, function (): void {
        expect(EngineConnection::onHold())->toBeTrue();
    });

    // The planting really reached the reader's three sources, so a green case
    // is a fact about the hold and not about an assignment that did nothing.
    withPlantedEngineHold($value, function () use ($value): void {
        expect($_SERVER['ENGINE_HOLD'] ?? null)->toBe($value)
            ->and($_ENV['ENGINE_HOLD'] ?? null)->toBe($value)
            ->and(getenv('ENGINE_HOLD'))->toBe($value);
    });
})->with(['0', 'off', 'false', 'no', 'OFF', '']);

it('refuses both engine entry points while a cleared ENGINE_HOLD is in the environment', function () {
    withPlantedEngineHold('0', function (): void {
        expect(static fn () => EngineConnection::connection())->toThrow(EngineOnHold::class)
            ->and(static fn () => EngineDatabase::engine())->toThrow(EngineOnHold::class);
    });
});

it('restores the environment it planted', function () {
    $before = $_SERVER['ENGINE_HOLD'] ?? null;

    withPlantedEngineHold('0', function (): void {
        expect($_SERVER['ENGINE_HOLD'] ?? null)->toBe('0');
    });

    expect($_SERVER['ENGINE_HOLD'] ?? null)->toBe($before);
});

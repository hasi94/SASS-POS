<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| T-018 step 10 - no HTTP surface for any outbox/event-bus operation
| (AC-33)
|--------------------------------------------------------------------------
|
| D-9: console commands and internal handlers only, on both sides. Checked
| against the live route table rather than the route files, so a route
| mounted from anywhere is caught the same way.
*/

/**
 * @return list<string>
 */
function qaEventbusHttpOffenders(): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower($route->uri());
        $action = strtolower((string) $route->getActionName());

        if (preg_match('/outbox|eventbus|dead.?letter/i', $uri) === 1 || preg_match('/outbox|eventbus|dead.?letter/i', $action) === 1) {
            $found[] = $route->uri();
        }
    }

    return $found;
}

it('AC-33: no registered route s URI or action matches outbox, eventbus or dead letter', function () {
    expect(qaEventbusHttpOffenders())->toBe([]);
});

it('control: a mounted scratch GET api/v1/outbox is reported', function () {
    Route::middleware('api')->get('api/v1/outbox', fn () => response()->json([]));

    expect(qaEventbusHttpOffenders())->toContain('api/v1/outbox');
});

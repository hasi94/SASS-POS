<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| T-019 step 15 - no HTTP surface for any plugin/hook operation (AC-43)
|--------------------------------------------------------------------------
|
| D-10: console commands and internal handlers only. Checked against the
| live route table rather than the route files, so a route mounted from
| anywhere is caught the same way.
*/

/**
 * @return list<string>
 */
function qaPluginHttpOffenders(): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower($route->uri());
        $action = strtolower((string) $route->getActionName());

        if (preg_match('/plugin|hook/i', $uri) === 1 || preg_match('/plugin|hook/i', $action) === 1) {
            $found[] = $route->uri();
        }
    }

    return $found;
}

it('AC-43: no registered route`s URI or action matches /plugin|hook/i', function () {
    expect(qaPluginHttpOffenders())->toBe([]);
});

it('control: a mounted scratch GET api/v1/plugins is reported', function () {
    Route::middleware('api')->get('api/v1/plugins', fn () => response()->json([]));

    expect(qaPluginHttpOffenders())->toContain('api/v1/plugins');
});

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| T-020 step 14 - no HTTP surface for any workflow operation (AC-26)
|--------------------------------------------------------------------------
|
| D-10: console commands only. Checked against the live route table rather
| than the route files, so a route mounted from anywhere is caught the
| same way.
*/

/**
 * @return list<string>
 */
function qaWorkflowHttpOffenders(): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower($route->uri());
        $action = strtolower((string) $route->getActionName());

        if (preg_match('/workflow|approval/i', $uri) === 1 || preg_match('/workflow|approval/i', $action) === 1) {
            $found[] = $route->uri();
        }
    }

    return $found;
}

it('AC-26: no registered route`s URI or action matches /workflow|approval/i', function () {
    expect(qaWorkflowHttpOffenders())->toBe([]);
});

it('control: a mounted scratch GET api/v1/workflows is reported', function () {
    Route::middleware('api')->get('api/v1/workflows', fn () => response()->json([]));

    expect(qaWorkflowHttpOffenders())->toContain('api/v1/workflows');
});

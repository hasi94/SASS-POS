<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| T-017 step 13 - no HTTP surface for reading audit records (AC-6, AC-17)
|--------------------------------------------------------------------------
|
| D-7: an internal handler/console command only, on both sides. Checked
| against the live route table rather than the route *files*, so a route
| mounted from anywhere - this package, another, or a future one - is
| caught the same way.
*/

/**
 * Every registered route whose URI or controller/closure action names
 * "audit", case-insensitively.
 *
 * @return list<string>
 */
function qaAuditHttpOffenders(): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower($route->uri());
        $action = strtolower((string) $route->getActionName());

        if (str_contains($uri, 'audit') || str_contains($action, 'audit')) {
            $found[] = $route->uri();
        }
    }

    return $found;
}

it('AC-6, AC-17: no registered route s URI or action names audit', function () {
    expect(qaAuditHttpOffenders())->toBe([]);
});

it('control: a mounted scratch GET api/v1/audit is reported', function () {
    Route::middleware('api')->get('api/v1/audit', fn () => response()->json([]));

    expect(qaAuditHttpOffenders())->toContain('api/v1/audit');
});

<?php

use App\Http\Controllers\RootController;
use Illuminate\Support\Facades\Route;

/*
 * An invokable controller, never an inline closure (T-004 AC-13).
 *
 * The stock scaffolding pointed `/` at an inline closure rendering the welcome
 * view. That is retired as dead scaffolding in a backend-only host, not because
 * it blocked route caching - it did not; Laravel 12 serialises a closure action,
 * and tests/Feature/RouteCachingTest.php pins that.
 *
 * This file must keep declaring at least one route. The two health probes are
 * registered from the `then` callback in bootstrap/app.php so they land outside
 * every middleware group; T-002's decision D-18 guarantees that callback adds to
 * routes/web.php rather than replacing it, and HealthEndpointTest proves it by
 * finding this route still inside the `web` group afterwards. With an empty
 * routes/web.php there would be nothing left to notice the difference.
 */

Route::get('/', RootController::class)->name('root');

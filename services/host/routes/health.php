<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Deliberately outside the `web` group (see bootstrap/app.php's `then` callback):
 * an orchestrator's liveness and readiness probes must not depend on the session
 * store. With the shipped SESSION_DRIVER=database, StartSession would open a
 * database connection on every probe and answer 500 while the process itself is
 * perfectly alive, so the orchestrator would kill a healthy container.
 *
 * Not wrapping these in a group at all - rather than stripping middleware off
 * them - is the framework's own idiom for this: Laravel registers its built-in
 * `health: '/up'` route the same way, with a bare Route::get() call. A route
 * that was never in a group cannot silently regress when the group's contents
 * change.
 */

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

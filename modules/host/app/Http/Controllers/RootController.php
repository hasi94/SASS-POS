<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The route on `/`.
 *
 * This host is a backend: there is no page to render and no front-end build, so
 * `/` answers 204 No Content rather than pretending to be a site. It replaces
 * the framework's stock `Route::get('/', fn () => view('welcome'))` (T-004
 * FP-157, decision D-39).
 *
 * The route exists at all, rather than being removed, because T-002's decision
 * D-18 relies on routes/web.php declaring something: the health routes are added
 * from the `then` callback in bootstrap/app.php, and if that callback ever
 * replaced routes/web.php instead of adding to it, an empty routes/web.php would
 * hide the mistake. HealthEndpointTest probes this route for exactly that.
 */
class RootController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}

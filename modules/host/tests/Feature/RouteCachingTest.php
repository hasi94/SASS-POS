<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

/*
 * T-004 FP-157.
 *
 * `route:cache` already succeeds on this branch, closure routes included -
 * measured before a line of this task was written. Laravel 12's
 * Route::prepareForSerialization() serialises a closure action with
 * SerializableClosure::unsigned(), so the LogicException("Unable to prepare route
 * [...] for serialization. Uses Closure.") that README.md, config/modulehost.php
 * and the T-002 plan all rested on does not exist in this framework version.
 *
 * So this file does not unblock anything. It pins a guarantee that was already
 * true but was proved by nobody, which is why the documentation could be wrong
 * for two tasks without the gate noticing. From here on, any change that breaks
 * route caching - this task's own scaffolding removal included - fails the gate
 * immediately, and the framework fact is an executing assertion rather than a
 * sentence in a comment.
 *
 * Shaped like ConfigCacheGuardTest, for the same reason: bootstrap/cache/routes-v7.php
 * is real, process-wide shared state, and every other test in the suite boots
 * uncached. Hence the pre-flight assertion, the child process, the finally block
 * and the afterEach.
 */

function routeCachePath(): string
{
    return app()->getCachedRoutesPath();
}

function routeCacheArtisan(string $command): ProcessResult
{
    return Process::path(base_path())
        ->env(['MODULES' => 'demo'])
        ->run([PHP_BINARY, base_path('artisan'), $command, '--no-ansi']);
}

afterEach(function () {
    File::delete(routeCachePath());
});

it('caches the route table successfully and leaves no cache file behind', function () {
    // AC-15's first half: a leftover file from an earlier crashed run would make
    // the whole case meaningless. The path is derived from the application, never
    // hard-coded as routes-v7.php, so a framework bump cannot silently make this
    // test watch a file nobody writes any more.
    expect(File::exists(routeCachePath()))
        ->toBeFalse('a leftover '.basename(routeCachePath()).' from an earlier crashed run would make this test meaningless');

    try {
        $cache = routeCacheArtisan('route:cache');
        $output = $cache->output().$cache->errorOutput();

        // AC-14. The two phrases are the exact wording of the exception the
        // documentation used to claim happens here, so if the framework ever
        // starts throwing it again this names the reason rather than just failing.
        expect($cache->exitCode())->toBe(0, 'route:cache failed: '.$output)
            ->and(str_contains($output, 'for serialization'))->toBeFalse()
            ->and(str_contains($output, 'Uses Closure'))->toBeFalse()
            ->and(File::exists(routeCachePath()))->toBeTrue();

        // The cached table is usable, not merely written: a child that boots with
        // it answers a route.
        $list = routeCacheArtisan('route:list');

        expect($list->exitCode())->toBe(0)
            ->and($list->output())->toContain('health/live');
    } finally {
        $clear = routeCacheArtisan('route:clear');

        // Delete before asserting, so a failed assertion above can never leave a
        // cached route table behind for the rest of the gate.
        File::delete(routeCachePath());

        // AC-15's second half.
        expect($clear->exitCode())->toBe(0)
            ->and(File::exists(routeCachePath()))->toBeFalse();
    }
});

it('still serialises a closure route on this framework version', function () {
    // The corrected framework fact, as an executing assertion rather than a
    // comment. Three closure routes remain after this task - /up from
    // bootstrap/app.php's health: option, and storage/{path} GET and PUT,
    // registered by FilesystemServiceProvider because config/filesystems.php's
    // local disk sets serve => true - so route caching depends on this behaviour
    // continuing to hold, not merely on routes/web.php having no closure.
    $route = new RoutingRoute('GET', '/route-caching-probe', fn () => null);

    // Asserted on the action itself, before and after. getActionName() returns a
    // string whatever happens, so asserting it is a string proved nothing (the
    // same shape as QA BUG-2). The action's `uses` is a Closure now and has to be
    // a serialised string afterwards - that is the behaviour being pinned, and it
    // fails if the framework ever stops serialising a closure action instead of
    // only when it throws.
    expect($route->getAction('uses'))->toBeInstanceOf(Closure::class);

    $route->prepareForSerialization();

    expect($route->getAction('uses'))->toBeString()
        ->and($route->getAction('uses'))->not->toBe('');

    // And the live table really does still contain closure actions, so the
    // assertion above is about this application and not a hypothetical one.
    $closures = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $candidate): bool => ($candidate->action['uses'] ?? null) instanceof Closure)
        ->map(fn (RoutingRoute $candidate): string => $candidate->uri())
        ->values()
        ->all();

    expect($closures)->not->toBe(
        [],
        'no closure route is left, so this case no longer proves that route caching survives one. '.
        'Re-point it at whatever closure route the framework registers, or retire it deliberately.'
    );
});

it('declares no closure route in routes/web.php', function () {
    // AC-13. Checked as source, because a controller route and a closure route
    // are indistinguishable once the router has resolved them.
    $source = File::get(base_path('routes'.DIRECTORY_SEPARATOR.'web.php'));

    foreach (['function (', 'fn (', 'fn('] as $shape) {
        expect(str_contains($source, $shape))->toBeFalse(
            'routes/web.php declares an inline closure ('.$shape.'); AC-13 requires a named controller action'
        );
    }

    $root = Route::getRoutes()->getByName('root');

    expect($root)->not->toBeNull()
        ->and($root?->getActionName())->toContain('RootController');
});

it('documents the route:cache constraint that is actually true', function () {
    // AC-16. The two places that documented a false reason for a real hazard.
    // Corrected, and asserted here so the correction cannot silently rot back.
    $documents = [
        'config/modulehost.php' => File::get(base_path('config'.DIRECTORY_SEPARATOR.'modulehost.php')),
        'README.md' => File::get(dirname(base_path(), 2).DIRECTORY_SEPARATOR.'README.md'),
    ];

    $offenders = [];

    foreach ($documents as $where => $text) {
        $lower = strtolower($text);

        foreach ([
            'fails outright',
            'closures cannot be serialised',
            'closures cannot be serialized',
            'stock closure route',
        ] as $falsehood) {
            if (str_contains($lower, $falsehood)) {
                $offenders[] = $where.': "'.$falsehood.'"';
            }
        }

        // The half that is true and still unguarded has to stay.
        expect($lower)->toContain('route:cache')
            ->and($lower)->toContain('freezes the route table');
    }

    expect($offenders)->toBe(
        [],
        'route:cache does not fail on this framework version and no stock closure route remains, so these '.
        'claims are false: '.implode('; ', $offenders)
    );
});

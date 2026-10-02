<?php

use Illuminate\Support\Facades\File;

/*
 * T-004 FP-157 (AC-12).
 *
 * The framework's stock example tests, welcome view and front-end build files
 * are gone. This host is a backend: nothing here renders a page or builds
 * JavaScript, and leaving the scaffolding in place advertised capabilities that
 * do not exist.
 *
 * Asserted rather than merely done, because `composer create-project`, a
 * framework upgrade guide or a well-meaning `npm init` would each happily put
 * them back, and nothing else in the suite would notice.
 */

it('leaves none of the stock scaffolding behind', function () {
    $gone = [];

    foreach ([
        'tests/Feature/ExampleTest.php',
        'tests/Unit/ExampleTest.php',
        'resources/views/welcome.blade.php',
        'resources/css/app.css',
        'resources/js/app.js',
        'resources/js/bootstrap.js',
        'vite.config.js',
        // Not named by AC-12, but it declared the build for every file above, so
        // leaving it would leave a manifest describing nothing.
        'package.json',
    ] as $path) {
        if (File::exists(base_path($path))) {
            $gone[] = $path;
        }
    }

    expect($gone)->toBe(
        [],
        'stock framework scaffolding is back in a backend-only host: '.implode(', ', $gone)
    );

    // The negative control: the same probe applied to a file that does exist has
    // to report it, or "nothing found" proves nothing.
    expect(File::exists(base_path('routes'.DIRECTORY_SEPARATOR.'web.php')))->toBeTrue();

    // No config/view.php ships, so the framework's default view path must still
    // exist even though no view does - hence resources/views/.gitkeep.
    expect(File::isDirectory(base_path('resources'.DIRECTORY_SEPARATOR.'views')))->toBeTrue()
        ->and(File::exists(base_path('resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'.gitkeep')))->toBeTrue();

    // And no npm/npx call is left in a script that would now fail.
    /** @var array{scripts?: array<string, mixed>} $manifest */
    $manifest = (array) json_decode((string) File::get(base_path('composer.json')), true);
    $scripts = (string) json_encode($manifest['scripts'] ?? []);

    // Positive control first, for the same reason: an empty scripts block would
    // satisfy both absence checks without proving anything about it.
    expect(str_contains($scripts, 'artisan'))->toBeTrue();

    expect(str_contains($scripts, 'npm '))->toBeFalse()
        ->and(str_contains($scripts, 'npx '))->toBeFalse();
});

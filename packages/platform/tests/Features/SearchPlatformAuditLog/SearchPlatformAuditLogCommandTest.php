<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\SearchPlatformAuditLog;

use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 12 - platform:audit-search (AC-18 platform half)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
});

afterEach(function () {
    /** @var list<string> $directories */
    $directories = $GLOBALS['platformSearchCommandFreshDirectories'] ?? [];

    foreach ($directories as $directory) {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    $GLOBALS['platformSearchCommandFreshDirectories'] = [];
});

function platformSearchCommandFreshPath(string $name): string
{
    $directory = sys_get_temp_dir().'/platform_audit_search_command_test_'.bin2hex(random_bytes(6));
    $GLOBALS['platformSearchCommandFreshDirectories'][] = $directory;

    mkdir($directory);

    return $directory.'/'.$name;
}

it('exports the page as JSON', function () {
    $staff = platformSearchSeedUser();
    platformSearchSeedRow();
    platformSearchSeedRow();

    $path = platformSearchCommandFreshPath('page.json');

    $exitCode = Artisan::call('platform:audit-search', [
        '--as' => $staff,
        '--output' => $path,
        '--format' => 'json',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toHaveCount(2);
});

it('clamps the export to max_page_size and prints the next cursor', function () {
    $staff = platformSearchSeedUser();

    for ($i = 0; $i < 7; $i++) {
        platformSearchSeedRow();
    }

    config()->set('audit.max_page_size', 5);

    $path = platformSearchCommandFreshPath('page.json');

    Artisan::call('platform:audit-search', [
        '--as' => $staff,
        '--output' => $path,
        '--format' => 'json',
    ]);

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toHaveCount(5)
        ->and(Artisan::output())->toContain('--before-id=');
});

it('exits non-zero for a refusal, with the reason printed', function () {
    $nonStaff = platformSearchSeedUser(['is_platform_staff' => false]);

    $exitCode = Artisan::call('platform:audit-search', ['--as' => $nonStaff]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('forbidden');
});
